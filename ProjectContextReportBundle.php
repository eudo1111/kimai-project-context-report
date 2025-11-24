<?php

namespace KimaiPlugin\ProjectContextReportBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class ProjectContextReportBundle extends Bundle implements PluginInterface
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
    }
} 