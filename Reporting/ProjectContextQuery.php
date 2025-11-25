<?php

namespace KimaiPlugin\ProjectContextReportBundle\Reporting;

use App\Entity\Project;
use App\Entity\User;
use DateTime;

final class ProjectContextQuery
{
    private ?Project $project = null;
    private ?DateTime $month = null;

    public function __construct(private DateTime $today, private User $user)
    {
    }

    public function getToday(): DateTime
    {
        return $this->today;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): void
    {
        $this->project = $project;
    }

    public function getMonth(): ?DateTime
    {
        return $this->month;
    }

    public function setMonth(?DateTime $month): void
    {
        $this->month = $month;
    }
}

