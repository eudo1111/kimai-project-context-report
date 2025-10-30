<?php

namespace KimaiPlugin\UserActivityReportBundle\Controller;

use App\Export\Spreadsheet\Writer\BinaryFileResponseWriter;
use App\Export\Spreadsheet\Writer\XlsxWriter;
use App\Reporting\MonthlyUserList\MonthlyUserList;
use App\Reporting\MonthlyUserList\MonthlyUserListForm;
use App\Repository\ActivityRepository;
use App\Repository\Query\TimesheetStatisticQuery;
use App\Repository\Query\UserQuery;
use App\Repository\Query\VisibilityInterface;
use App\Repository\UserRepository;
use App\Timesheet\TimesheetStatisticService;
use App\Controller\AbstractController;
use PhpOffice\PhpSpreadsheet\Reader\Html;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route(path: '/reporting/customer/monthly_activities')]
#[IsGranted('report:customer')]
final class CustomerMonthlyActivityController extends AbstractController
{
    #[Route(path: '/view', name: 'report_customer_monthly_activity', methods: ['GET', 'POST'])]
    public function report(Request $request, TimesheetStatisticService $statisticService, UserRepository $userRepository, ActivityRepository $activityRepository): Response
    {
        return $this->render('@UserActivityReport/monthly_activities.html.twig', $this->getData($request, $statisticService, $userRepository, $activityRepository));
    }

    #[Route(path: '/export', name: 'report_customer_monthly_activity_export', methods: ['GET', 'POST'])]
    public function export(Request $request, TimesheetStatisticService $statisticService, UserRepository $userRepository, ActivityRepository $activityRepository): Response
    {
        $data = $this->getData($request, $statisticService, $userRepository, $activityRepository);

        $content = $this->renderView('@UserActivityReport/monthly_activities.html.twig', $data);

        $reader = new Html();
        $spreadsheet = $reader->loadFromString($content);

        $writer = new BinaryFileResponseWriter(new XlsxWriter(), 'kimai-export-customer-activity-sum');

        return $writer->getFileResponse($spreadsheet);
    }

    private function getData(Request $request, TimesheetStatisticService $statisticService, UserRepository $userRepository, ActivityRepository $activityRepository): array
    {
        $currentUser = $this->getUser();
        $dateTimeFactory = $this->getDateTimeFactory();

        $values = new MonthlyUserList();
        $values->setDate($dateTimeFactory->getStartOfMonth());

        $form = $this->createFormForGetRequest(MonthlyUserListForm::class, $values, [
            'timezone' => $dateTimeFactory->getTimezone()->getName(),
            'start_date' => $values->getDate(),
        ]);
        $form->submit($request->query->all(), false);

        $query = new UserQuery();
        $query->setVisibility(VisibilityInterface::SHOW_BOTH);
        $query->setSystemAccount(false);
        $query->setCurrentUser($currentUser);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($values->getTeam() !== null) {
                $query->setSearchTeams([$values->getTeam()]);
            }
        } else {
            $values->setDate($dateTimeFactory->getStartOfMonth());
        }

        $allUsers = $userRepository->getUsersForQuery($query);

        $start = $values->getDate() ?? $dateTimeFactory->getStartOfMonth();
        $start->modify('first day of 00:00:00');
        $end = clone $start;
        $end->modify('last day of 23:59:59');

        $hasData = true;
        $activityTotals = [];
        $usersById = [];
        foreach ($allUsers as $u) {
            $usersById[(string) $u->getId()] = $u;
        }

        if (!empty($allUsers)) {
            $grouped = $statisticService->getDailyStatisticsGrouped($start, $end, $allUsers);

            // Build activity -> user -> totals (duration/rates) across the month
            $selectedProjectId = null;
            if ($values->getProject() !== null) {
                $selectedProjectId = (string) $values->getProject()->getId();
            }
            foreach ($grouped as $uid => $projects) {
                foreach ($projects as $projectData) {
                    if ($selectedProjectId !== null && (string) $projectData['project'] !== $selectedProjectId) {
                        continue;
                    }
                    foreach ($projectData['activities'] as $aid => $activityData) {
                        if (!isset($activityTotals[$aid])) {
                            $activityTotals[$aid] = ['activity' => $aid, 'perUser' => [], 'duration' => 0, 'rate' => 0.0, 'internalRate' => 0.0];
                        }
                        $days = $activityData['data'];
                        $totalDuration = 0;
                        $totalRate = 0.0;
                        $totalInternal = 0.0;
                        foreach ($days->getDays() as $day) {
                            $totalDuration += $day->getTotalDuration();
                            $totalRate += $day->getTotalRate();
                            $totalInternal += $day->getTotalInternalRate();
                        }
                        if (!isset($activityTotals[$aid]['perUser'][$uid])) {
                            $activityTotals[$aid]['perUser'][$uid] = ['duration' => 0, 'rate' => 0.0, 'internalRate' => 0.0];
                        }
                        $activityTotals[$aid]['perUser'][$uid]['duration'] += $totalDuration;
                        $activityTotals[$aid]['perUser'][$uid]['rate'] += $totalRate;
                        $activityTotals[$aid]['perUser'][$uid]['internalRate'] += $totalInternal;
                        $activityTotals[$aid]['duration'] += $totalDuration;
                        $activityTotals[$aid]['rate'] += $totalRate;
                        $activityTotals[$aid]['internalRate'] += $totalInternal;
                    }
                }
            }
        } else {
            $hasData = false;
        }

        // Map activity IDs to entities/names
        $activityIds = array_keys($activityTotals);
        $activities = [];
        if (!empty($activityIds)) {
            $activityEntities = $activityRepository->findBy(['id' => $activityIds]);
            foreach ($activityEntities as $activity) {
                $activities[(string) $activity->getId()] = $activity;
            }
        }

        // Reduce users to only those that have any reported time for the selection
        $userIdsWithData = [];
        foreach ($activityTotals as $totals) {
            foreach (array_keys($totals['perUser']) as $uid) {
                $userIdsWithData[$uid] = true;
            }
        }
        $usersWithData = [];
        foreach ($userIdsWithData as $uid => $_) {
            if (isset($usersById[$uid])) {
                $usersWithData[] = $usersById[$uid];
            }
        }


        return [
            'report_title' => 'report_customer_monthly_activity',
            'export_route' => 'report_customer_monthly_activity_export',
            'form' => $form->createView(),
            'date' => $start,
            'sumType' => $values->getSumType(),
            'users' => $usersWithData,
            'usersById' => $usersById,
            'activities' => $activities,
            'activityTotals' => $activityTotals,
            'hasData' => $hasData,
            'decimal' => $values->isDecimal(),
        ];
    }
}


