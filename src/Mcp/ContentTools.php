<?php

namespace App\Mcp;

use App\Entity\Content;
use App\Form\ContentType;
use App\Service\ContentService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ContentTools extends AbstractFormTool
{
    public function __construct(
        FormFactoryInterface $forms,
        Security $security,
        UrlGeneratorInterface $router,
        private readonly ContentService $service,
    ) {
        parent::__construct($forms, $security, $router);
    }

    #[McpTool(name: 'content_list', description: 'List content pages without their HTML body.')]
    public function list(): array
    {
        $this->requireRole('ROLE_ADMIN_CONTENT');

        return array_map(fn (Content $content) => $this->summary($content), array_values($this->service->getAll()));
    }

    #[McpTool(name: 'content_get', description: 'Get a content page including HTML by exactly one of id or alias.')]
    public function get(?int $id = null, ?string $alias = null): array
    {
        $this->requireRole('ROLE_ADMIN_CONTENT');
        if ((null === $id) === (null === $alias)) {
            throw new ToolCallException('Genau eine ID oder ein Alias muss angegeben werden.');
        }
        $content = null !== $id ? $this->service->getById($id) : $this->service->getByAlias($alias);
        if (null === $content) {
            throw new ToolCallException('Content nicht gefunden.');
        }

        return $this->details($content);
    }

    #[McpTool(name: 'content_create', description: 'Create a content page. The content is HTML and may contain scripts.')]
    public function create(
        #[Schema(minLength: 1, maxLength: 255)] string $title,
        string $content,
        #[Schema(maxLength: 255)] ?string $description = null,
        #[Schema(maxLength: 255)] ?string $alias = null,
    ): array {
        $this->requireRole('ROLE_ADMIN_CONTENT');
        $entity = new Content();
        $this->submit(ContentType::class, $entity, compact('title', 'content', 'description', 'alias'));
        $this->service->save($entity);

        return $this->details($entity);
    }

    #[McpTool(name: 'content_update', description: 'Partially update a content page. Omitted fields stay unchanged; null clears a field (title cannot be cleared). Content is HTML and may contain scripts.')]
    public function update(
        int $id,
        #[Schema(type: 'object', properties: [
            'title' => ['type' => ['string', 'null'], 'maxLength' => 255],
            'content' => ['type' => ['string', 'null']],
            'description' => ['type' => ['string', 'null'], 'maxLength' => 255],
            'alias' => ['type' => ['string', 'null'], 'maxLength' => 255],
        ], additionalProperties: false)] array $fields,
    ): array {
        $this->requireRole('ROLE_ADMIN_CONTENT');
        $entity = $this->service->getById($id);
        if (null === $entity) {
            throw new ToolCallException('Content nicht gefunden.');
        }
        $this->submit(ContentType::class, $entity, $fields);
        $this->service->save($entity);

        return $this->details($entity);
    }

    private function summary(Content $content): array
    {
        return [
            'id' => $content->getId(),
            'title' => $content->getTitle(),
            'alias' => $content->getAlias(),
            'description' => $content->getDescription(),
            'lastModified' => $content->getLastModified()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function details(Content $content): array
    {
        return $this->summary($content) + [
            'content' => $content->getContent(),
            'adminEditUrl' => $this->router->generate('admin_content_edit', ['id' => $content->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            'publicUrl' => $this->router->generate('content', ['id' => $content->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ] + $this->history($content);
    }
}
