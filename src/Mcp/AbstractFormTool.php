<?php

namespace App\Mcp;

use App\Entity\Traits\HistoryAwareEntity;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

abstract class AbstractFormTool
{
    public function __construct(
        private readonly FormFactoryInterface $forms,
        private readonly Security $security,
        protected readonly UrlGeneratorInterface $router,
    ) {
    }

    protected function requireRole(string $role): void
    {
        if (!$this->security->isGranted($role)) {
            throw new ToolCallException('Keine Berechtigung für dieses Tool.');
        }
    }

    protected function submit(string $type, object $entity, array $data, array $options = []): void
    {
        if (array_key_exists('title', $data) && (null === $data['title'] || '' === trim($data['title']))) {
            throw new ToolCallException('title: Der Titel darf nicht leer sein.');
        }

        $form = $this->forms->create($type, $entity, ['csrf_protection' => false] + $options);
        $form->submit($data, false);
        if (!$form->isValid()) {
            $errors = [];
            foreach ($form->getErrors(true) as $error) {
                $errors[] = $error->getOrigin()->getName().': '.$error->getMessage();
            }
            throw new ToolCallException(implode("\n", $errors));
        }
    }

    protected function history(HistoryAwareEntity $entity): array
    {
        return [
            'created' => $entity->getCreated()?->format(\DateTimeInterface::ATOM),
            'lastModified' => $entity->getLastModified()?->format(\DateTimeInterface::ATOM),
            'authorId' => $entity->getAuthorId()?->toString(),
            'modifierId' => $entity->getModifierId()?->toString(),
        ];
    }
}
