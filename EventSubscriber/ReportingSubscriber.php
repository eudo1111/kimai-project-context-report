<?php

namespace KimaiPlugin\ProjectContextReportBundle\EventSubscriber;

use App\Event\ReportingEvent;
use App\Reporting\Report;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ReportingSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuthorizationCheckerInterface $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ReportingEvent::class => ['onReporting'],
        ];
    }

    public function onReporting(ReportingEvent $event): void
    {
        $auth = $this->security;

        if (!$auth->isGranted('view_reporting')) {
            return;
        }

        $event->addReport(new Report('report_project_context', 'report_project_context', 'Project Context', 'project'));
    }
}