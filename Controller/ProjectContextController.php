<?php

namespace KimaiPlugin\ProjectContextReportBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\Project;
use App\Model\ActivityStatistic;
use App\Project\ProjectStatisticService;
use App\Repository\ActivityRepository;
use App\Repository\TimesheetRepository;
use App\Repository\UserRepository;
use App\Form\Model\DateRange;
use App\Utils\PageSetup;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use KimaiPlugin\ProjectContextReportBundle\Reporting\ProjectContextForm;
use KimaiPlugin\ProjectContextReportBundle\Reporting\ProjectContextQuery;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/reporting/project/context')]
#[IsGranted('report:project')]
#[IsGranted(new Expression("is_granted('details', 'project')"))]
final class ProjectContextController extends AbstractController
{
    #[Route(path: '/view', name: 'report_project_context', methods: ['GET'])]
    public function report(
        Request $request,
        ProjectStatisticService $service,
        TimesheetRepository $timesheetRepository,
        ActivityRepository $activityRepository,
        UserRepository $userRepository
    ): Response
    {
        $dateFactory = $this->getDateTimeFactory();
        $user = $this->getUser();

        $defaultStart = $dateFactory->getStartOfMonth();
        $query = new ProjectContextQuery($dateFactory->createDateTime(), $user);
        $form = $this->createFormForGetRequest(ProjectContextForm::class, $query, [
            'timezone' => $user->getTimezone()
        ]);
        $form->submit($request->query->all(), false);

        $project = $query->getProject();
        $month = $query->getMonth() ?? $defaultStart;

        $dateRange = new DateRange(true);
        $dateRange->setBegin($month);
        $monthEnd = $dateFactory->getEndOfMonth($dateRange->getBegin());
        $dateRange->setEnd($monthEnd);

        $monthStart = $dateRange->getBegin();
        $monthEnd = $dateRange->getEnd();

        $projectView = null;
        $filteredActivities = [];
        $userActivity = null;

        if ($project !== null && $this->isGranted('details', $project)) {
            $projectViews = $service->getProjectView($user, [$project], $query->getToday());
            $projectView = $projectViews[0];
            
            // Filter activity statistics by the selected month
            $filteredActivities = $this->getFilteredActivityStatistics(
                $project,
                $monthStart,
                $monthEnd,
                $timesheetRepository,
                $activityRepository
            );
            
            $userActivity = $this->buildUserActivityMatrix(
                $project,
                $monthStart,
                $monthEnd,
                $timesheetRepository,
                $activityRepository,
                $userRepository
            );
        }

        $page = new PageSetup('projects');
        $page->setHelp('project.html');

        if ($project !== null) {
            $page->setActionName('project');
            $page->setActionView('project_context_report');
            $page->setActionPayload(['project' => $project]);
        }

        $view_revenue = $project !== null && $this->isGranted('view_rate_other_timesheet');

        return $this->render('@ProjectContextReport/project_context.html.twig', [
            'page_setup' => $page,
            'report_title' => 'report_project_context',
            'project' => $project,
            'project_view' => $projectView,
            'activities' => $filteredActivities,
            'form' => $form->createView(),
            'now' => $this->getDateTimeFactory()->createDateTime(),
            'view_revenue' => $view_revenue,
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
            'user_activity' => $userActivity,
        ]);
    }

    /**
     * @return ActivityStatistic[]
     */
    private function getFilteredActivityStatistics(
        Project $project,
        DateTimeInterface $begin,
        DateTimeInterface $end,
        TimesheetRepository $timesheetRepository,
        ActivityRepository $activityRepository
    ): array {
        $qb = $timesheetRepository->createQueryBuilder('t');
        $qb
            ->select('IDENTITY(t.activity) as activity')
            ->addSelect('COALESCE(SUM(t.duration), 0) as duration')
            ->addSelect('COALESCE(SUM(t.rate), 0) as rate')
            ->addSelect('COALESCE(SUM(t.internalRate), 0) as internalRate')
            ->addSelect('COUNT(t.id) as count')
            ->addSelect('t.billable as billable')
            ->where($qb->expr()->eq('t.project', ':project'))
            ->andWhere($qb->expr()->isNotNull('t.end'))
            ->andWhere($qb->expr()->between('t.begin', ':begin', ':end'))
            ->setParameter('project', $project)
            ->setParameter('begin', DateTimeImmutable::createFromInterface($begin), Types::DATETIME_IMMUTABLE)
            ->setParameter('end', DateTimeImmutable::createFromInterface($end), Types::DATETIME_IMMUTABLE)
            ->addGroupBy('t.activity')
            ->addGroupBy('t.billable')
        ;

        $results = $qb->getQuery()->getArrayResult();

        /** @var array<int, ActivityStatistic> $activities */
        $activities = [];
        $activityIds = [];

        foreach ($results as $row) {
            $activityId = (int) $row['activity'];
            $activityIds[$activityId] = $activityId;

            if (!isset($activities[$activityId])) {
                $activity = new ActivityStatistic();
                $activities[$activityId] = $activity;
            } else {
                $activity = $activities[$activityId];
            }

            $activity->setRate($activity->getRate() + (float) $row['rate']);
            $activity->setDuration($activity->getDuration() + (int) $row['duration']);
            $activity->setInternalRate($activity->getInternalRate() + (float) $row['internalRate']);
            $activity->setCounter($activity->getCounter() + (int) $row['count']);

            if ($row['billable']) {
                $activity->setDurationBillable($activity->getDurationBillable() + (int) $row['duration']);
                $activity->setRateBillable($activity->getRateBillable() + (float) $row['rate']);
                $activity->setInternalRateBillable($activity->getInternalRateBillable() + (float) $row['internalRate']);
            }
        }

        if (!empty($activityIds)) {
            $activityEntities = $activityRepository->findBy(['id' => array_values($activityIds)]);
            foreach ($activityEntities as $entity) {
                if (isset($activities[$entity->getId()])) {
                    $activities[$entity->getId()]->setActivity($entity);
                }
            }
        }

        return array_values($activities);
    }

    private function buildUserActivityMatrix(
        Project $project,
        DateTimeInterface $begin,
        DateTimeInterface $end,
        TimesheetRepository $timesheetRepository,
        ActivityRepository $activityRepository,
        UserRepository $userRepository
    ): array {
        $qb = $timesheetRepository->createQueryBuilder('t');
        $qb
            ->select('IDENTITY(t.user) as user_id')
            ->addSelect('IDENTITY(t.activity) as activity_id')
            ->addSelect('COALESCE(SUM(t.duration), 0) as duration')
            ->addSelect('COALESCE(SUM(t.rate), 0) as rate')
            ->where($qb->expr()->eq('t.project', ':project'))
            ->andWhere($qb->expr()->isNotNull('t.end'))
            ->andWhere($qb->expr()->between('t.begin', ':begin', ':end'))
            ->setParameter('project', $project)
            ->setParameter('begin', $begin)
            ->setParameter('end', $end)
            ->groupBy('t.user')
            ->addGroupBy('t.activity')
        ;

        $results = $qb->getQuery()->getArrayResult();

        $matrix = [];
        $userTotals = [];
        $activityTotals = [];
        $totalDuration = 0;
        $userIds = [];
        $activityIds = [];

        foreach ($results as $row) {
            $duration = (int) $row['duration'];
            if ($duration <= 0) {
                continue;
            }
            $uid = (int) $row['user_id'];
            $aid = (int) $row['activity_id'];

            $matrix[$uid][$aid] = ($matrix[$uid][$aid] ?? 0) + $duration;
            $userTotals[$uid] = ($userTotals[$uid] ?? 0) + $duration;
            $activityTotals[$aid] = ($activityTotals[$aid] ?? 0) + $duration;
            $totalDuration += $duration;

            $userIds[$uid] = $uid;
            $activityIds[$aid] = $aid;
        }

        $users = [];
        if (!empty($userIds)) {
            $userEntities = $userRepository->findBy(['id' => array_values($userIds)]);
            foreach ($userEntities as $entity) {
                $users[$entity->getId()] = [
                    'id' => $entity->getId(),
                    'name' => $entity->getDisplayName(),
                    'color' => $entity->getColor(),
                ];
            }
            uasort($users, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        }

        $activities = [];
        if (!empty($activityIds)) {
            $activityEntities = $activityRepository->findBy(['id' => array_values($activityIds)]);
            foreach ($activityEntities as $entity) {
                $activities[$entity->getId()] = [
                    'id' => $entity->getId(),
                    'name' => $entity->getName(),
                ];
            }
            uasort($activities, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        }

        return [
            'hasData' => $totalDuration > 0,
            'users' => array_values($users),
            'activities' => array_values($activities),
            'matrix' => $matrix,
            'userTotals' => $userTotals,
            'activityTotals' => $activityTotals,
            'totalDuration' => $totalDuration,
            'begin' => $begin,
            'end' => $end,
        ];
    }
}


