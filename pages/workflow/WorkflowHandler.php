<?php

/**
 * @file pages/workflow/WorkflowHandler.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class WorkflowHandler
 *
 * @ingroup pages_reviewer
 *
 * @brief Handle requests for the submission workflow.
 */

namespace APP\pages\workflow;

use APP\core\Application;
use APP\core\Services;
use APP\decision\types\Decline;
use APP\decision\types\RevertDecline;
use APP\facades\Repo;
use APP\file\PublicFileManager;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Exception;
use PKP\components\forms\publication\TitleAbstractForm;
use PKP\context\Context;
use PKP\notification\PKPNotification;
use PKP\pages\workflow\PKPWorkflowHandler;
use PKP\plugins\Hook;
use PKP\security\Role;

class WorkflowHandler extends PKPWorkflowHandler
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->addRoleAssignment(
            [Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_ASSISTANT],
            [
                'access', 'index', 'submission',
                'editorDecisionActions', // Submission & review
                'externalReview', // review
                'editorial',
                'production',
                'submissionHeader',
                'submissionProgressBar',
            ]
        );
    }

    /**
     * Setup variables for the template
     *
     * @param \APP\core\Request $request
     */
    public function setupIndex($request)
    {
        parent::setupIndex($request);

        $templateMgr = TemplateManager::getManager($request);
        $submission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);

        $submissionContext = $request->getContext();
        if ($submission->getContextId() !== $submissionContext->getId()) {
            $submissionContext = Services::get('context')->get($submission->getContextId());
        }

        $locales = $submissionContext->getSupportedSubmissionLocaleNames();
        $locales = array_map(fn (string $locale, string $name) => ['key' => $locale, 'label' => $name], array_keys($locales), $locales);

        $latestPublication = $submission->getLatestPublication();

        $latestPublicationApiUrl = $request->getDispatcher()->url($request, Application::ROUTE_API, $submissionContext->getPath(), 'submissions/' . $submission->getId() . '/publications/' . $latestPublication->getId());
        $temporaryFileApiUrl = $request->getDispatcher()->url($request, Application::ROUTE_API, $submissionContext->getPath(), 'temporaryFiles');
        $issueApiUrl = $request->getDispatcher()->url($request, Application::ROUTE_API, $submissionContext->getData('urlPath'), 'issues/__issueId__');
        // $relatePublicationApiUrl = $request->getDispatcher()->url($request, Application::ROUTE_API, $submissionContext->getPath(), 'submissions/' . $submission->getId() . '/publications/' . $latestPublication->getId()) . '/relate';

        $publicFileManager = new PublicFileManager();
        $baseUrl = $request->getBaseUrl() . '/' . $publicFileManager->getContextFilesPath($submissionContext->getId());

        $issueEntryForm = new \APP\components\forms\publication\IssueEntryForm($latestPublicationApiUrl, $locales, $latestPublication, $submissionContext, $baseUrl, $temporaryFileApiUrl);
        // $relationForm = new \APP\components\forms\publication\RelationForm($relatePublicationApiUrl, $latestPublication);

        $sectionWordLimits = [];
        $sections = Repo::section()->getCollector()->filterByContextIds([$submissionContext->getId()])->getMany();
        foreach ($sections as $section) {
            $sectionWordLimits[$section->getId()] = (int) $section->getAbstractWordCount() ?? 0;
        }
        
        class_exists(\APP\components\forms\publication\AssignToIssueForm::class); // Force define of FORM_ASSIGN_TO_ISSUE
        $templateMgr->setConstants([
            // 'FORM_ID_RELATION' => FORM_ID_RELATION,
            'FORM_ASSIGN_TO_ISSUE' => FORM_ASSIGN_TO_ISSUE,
            'FORM_ISSUE_ENTRY' => FORM_ISSUE_ENTRY,
            'FORM_PUBLISH' => FORM_PUBLISH,
        ]);

        $components = $templateMgr->getState('components');
        $components[FORM_ISSUE_ENTRY] = $issueEntryForm->getConfig();

        // Add payments form if enabled
        $paymentManager = Application::getPaymentManager($submissionContext);
        $templateMgr->assign([
            'submissionPaymentsEnabled' => $paymentManager->publicationEnabled(),
        ]);
        if ($paymentManager->publicationEnabled()) {
            $submissionPaymentsForm = new \APP\components\forms\publication\SubmissionPaymentsForm(
                $request->getDispatcher()->url($request, Application::ROUTE_API, $submissionContext->getPath(), '_submissions/' . $submission->getId() . '/payment'),
                $submission,
                $request->getContext()
            );
            $components[FORM_SUBMISSION_PAYMENTS] = $submissionPaymentsForm->getConfig();
            $templateMgr->setConstants([
                'FORM_SUBMISSION_PAYMENTS' => FORM_SUBMISSION_PAYMENTS,
            ]);
        }

        // Add the word limit to the existing title/abstract form
        if (!empty($components[FORM_TITLE_ABSTRACT]) &&
                array_key_exists($submission->getLatestPublication()->getData('sectionId'), $sectionWordLimits)) {
            $limit = (int) $sectionWordLimits[$submission->getLatestPublication()->getData('sectionId')];
            foreach ($components[FORM_TITLE_ABSTRACT]['fields'] as $key => $field) {
                if ($field['name'] === 'abstract') {
                    $components[FORM_TITLE_ABSTRACT]['fields'][$key]['wordLimit'] = $limit;
                    break;
                }
            }
        }
        
        $assignToIssueUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_COMPONENT,
            null,
            'modals.publish.AssignToIssueHandler',
            'assign',
            null,
            [
                'submissionId' => $submission->getId(),
                'publicationId' => '__publicationId__',
            ]
        );
        
        // $components[FORM_ID_RELATION] = $relationForm->getConfig();

        $publicationFormIds = $templateMgr->getState('publicationFormIds');
        $publicationFormIds[] = FORM_ISSUE_ENTRY;

        $templateMgr->setState([
            'assignToIssueUrl' => $assignToIssueUrl,
            'components' => $components,
            'publicationFormIds' => $publicationFormIds,
            'issueApiUrl' => $issueApiUrl,
            'sectionWordLimits' => $sectionWordLimits,
            'selectIssueLabel' => __('publication.selectIssue'),
        ]);
    }


    //
    // Protected helper methods
    //
    /**
     * Return the editor assignment notification type based on stage id.
     *
     * @param int $stageId
     *
     * @return ?int
     */
    protected function getEditorAssignmentNotificationTypeByStageId($stageId)
    {
        if ($stageId !== WORKFLOW_STAGE_ID_PRODUCTION) {
            throw new Exception('Only the production stage is supported in OPS.');
        }
        switch ($stageId) {
            case WORKFLOW_STAGE_ID_PRODUCTION:
                return PKPNotification::NOTIFICATION_TYPE_EDITOR_ASSIGNMENT_PRODUCTION;
        }
        return null;
    }

    protected function _getRepresentationsGridUrl($request, $submission)
    {
        return $request->getDispatcher()->url(
            $request,
            Application::ROUTE_COMPONENT,
            null,
            'grid.articleGalleys.ArticleGalleyGridHandler',
            'fetchGrid',
            null,
            [
                'submissionId' => $submission->getId(),
                'publicationId' => '__publicationId__',
            ]
        );
    }

    protected function getStageDecisionTypes(int $stageId): array
    {
        if ($stageId !== WORKFLOW_STAGE_ID_PRODUCTION) {
            throw new Exception('Only the production stage is supported in OPS.');
        }

        $submission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);

        $decisionTypes = [];
        switch ($stageId) {
            case WORKFLOW_STAGE_ID_PRODUCTION:
                if ($submission->getData('status') === Submission::STATUS_DECLINED) {
                    $decisionTypes[] = new RevertDecline();
                } elseif ($submission->getData('status') === Submission::STATUS_QUEUED) {
                    $decisionTypes[] = new Decline();
                }
                break;
        }

        Hook::call('Workflow::Decisions', [&$decisionTypes, $stageId]);

        return $decisionTypes;
    }

    protected function getStageRecommendationTypes(int $stageId): array
    {
        if ($stageId !== WORKFLOW_STAGE_ID_PRODUCTION) {
            throw new Exception('Only the production stage is supported in OPS.');
        }

        $decisionTypes = [];

        Hook::call('Workflow::Recommendations', [$decisionTypes, $stageId]);

        return $decisionTypes;
    }

    protected function getPrimaryDecisionTypes(): array
    {
        return [];
    }

    protected function getWarnableDecisionTypes(): array
    {
        return [
            Decline::class,
        ];
    }

    protected function getTitleAbstractForm(string $latestPublicationApiUrl, array $locales, Publication $latestPublication, Context $context): TitleAbstractForm
    {
        $section = Repo::section()->get($latestPublication->getData('sectionId'), $context->getId());

        return new TitleAbstractForm(
            $latestPublicationApiUrl,
            $locales,
            $latestPublication,
            (int) $section->getData('wordCount'),
            !$section->getData('abstractsNotRequired')
        );
    }
}
