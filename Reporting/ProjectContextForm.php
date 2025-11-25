<?php

namespace KimaiPlugin\ProjectContextReportBundle\Reporting;

use App\Form\Type\MonthPickerType;
use App\Form\Type\ProjectType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ProjectContextQuery>
 */
final class ProjectContextForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $projectOptions = [
            'ignore_date' => true,
            'required' => false,
            'width' => false,
            'join_customer' => true,
        ];

        $builder->add('project', ProjectType::class, $projectOptions);

        $builder->add('month', MonthPickerType::class, [
            'required' => false,
            'label' => false,
            'view_timezone' => $options['timezone'],
            'model_timezone' => $options['timezone'],
        ]);

        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            function (FormEvent $event) use ($projectOptions): void {
                $data = $event->getData();
                if (isset($data['project']) && !empty($data['project'])) {
                    $projectId = $data['project'];
                    $projects = [];
                    if (\is_int($projectId) || \is_string($projectId)) {
                        $projects = [$projectId];
                    }

                    $event->getForm()->add('project', ProjectType::class, array_merge($projectOptions, [
                        'projects' => $projects
                    ]));
                }
            }
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProjectContextQuery::class,
            'timezone' => date_default_timezone_get(),
            'csrf_protection' => false,
            'method' => 'GET',
        ]);
    }
}

