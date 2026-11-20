<?php

namespace App\Form;

use App\Entity\Clan;
use App\Repository\ClanRepository;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClanSelectType extends AbstractType
{
    private readonly ClanRepository $clanRepository;

    public function __construct(ClanRepository $clanRepository)
    {
        $this->clanRepository = $clanRepository;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addViewTransformer(
            new CallbackTransformer(
                $this->transform(...),
                $options['hydrate'] ? $this->reverseTransform(...) : $this->reverseTransformUuid(...)
            )
        );
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['remoteController'] = 'api_clans';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'multiple' => false,
            'compound' => false,
            'hydrate' => true,
        ]);
        $resolver->setAllowedTypes('hydrate', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'select2';
    }

    public function transform($entity): array
    {
        $data = [];
        if (empty($entity)) {
            return $data;
        }

        switch (true) {
            case $entity instanceof Clan:
                $data[$entity->getUuid()->toString()] = $entity->getName();
                break;
            case $entity instanceof UuidInterface:
                $clan = $this->clanRepository->findOneByUuid($entity);
                if (!$clan) {
                    throw new TransformationFailedException('Unknown clan');
                }
                $data[$entity->toString()] = $clan->getName();
                break;
            default:
                throw new TransformationFailedException('Unknown type to convert');
        }

        return $data;
    }

    public function reverseTransform($value): ?Clan
    {
        if (empty($value)) {
            return null;
        }

        $value = $value instanceof UuidInterface ? $value : Uuid::fromString($value);
        return $this->clanRepository->findOneByUuid($value);
    }

    public function reverseTransformUuid($value): ?UuidInterface
    {
        if (empty($value)) {
            return null;
        }
        return $value instanceof UuidInterface ? $value : Uuid::fromString($value);
    }
}
