<?php

namespace App\Mcp;

use App\Entity\News;
use App\Form\NewsType;
use App\Service\NewsService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

final class NewsTools extends AbstractFormTool
{
    public function __construct(
        FormFactoryInterface $forms,
        Security $security,
        UrlGeneratorInterface $router,
        private readonly NewsService $service,
        private readonly UploaderHelper $uploader,
        private readonly UrlHelper $urlHelper,
    ) {
        parent::__construct($forms, $security, $router);
    }

    #[McpTool(name: 'news_list', description: 'List all news, including inactive entries, without HTML. limit is a positive count and offset is zero-based.')]
    public function list(#[Schema(minimum: 1)] int $limit = 20, #[Schema(minimum: 0)] int $offset = 0): array
    {
        $this->requireRole('ROLE_ADMIN_NEWS');

        return array_map(fn (News $news) => $this->summary($news), $this->service->getAll($offset, $limit));
    }

    #[McpTool(name: 'news_get', description: 'Get a news entry including HTML and its read-only image URL.')]
    public function get(int $id): array
    {
        $this->requireRole('ROLE_ADMIN_NEWS');

        return $this->details($this->find($id));
    }

    #[McpTool(name: 'news_create', description: 'Create news with an HTML body. Dates must be ISO 8601 with timezone (e.g. 2026-01-02T12:30:00+01:00); converted to the app timezone and stored to the minute.')]
    public function create(
        #[Schema(minLength: 1, maxLength: 255)] string $title,
        string $content,
        ?string $publishedFrom = null,
        ?string $publishedTo = null,
    ): array {
        $this->requireRole('ROLE_ADMIN_NEWS');
        $entity = new News();
        $data = $this->normalizeDates(compact('title', 'content', 'publishedFrom', 'publishedTo'));
        $this->submit(NewsType::class, $entity, $data, ['html5_dates' => true]);
        $this->service->save($entity);

        return $this->details($entity);
    }

    #[McpTool(name: 'news_update', description: 'Partially update news; the image is unchanged. Omitted fields stay unchanged; null clears a field (title cannot be cleared). Content is HTML. Dates must be ISO 8601 with timezone and are stored to the minute in the app timezone.')]
    public function update(
        int $id,
        #[Schema(type: 'object', properties: [
            'title' => ['type' => ['string', 'null'], 'maxLength' => 255],
            'content' => ['type' => ['string', 'null']],
            'publishedFrom' => ['type' => ['string', 'null']],
            'publishedTo' => ['type' => ['string', 'null']],
        ], additionalProperties: false)] array $fields,
    ): array {
        $this->requireRole('ROLE_ADMIN_NEWS');
        $entity = $this->find($id);
        $this->submit(NewsType::class, $entity, $this->normalizeDates($fields), ['html5_dates' => true]);
        $this->service->save($entity);

        return $this->details($entity);
    }

    private function find(int $id): News
    {
        $news = $this->service->getById($id);
        if (null === $news) {
            throw new ToolCallException('News nicht gefunden.');
        }

        return $news;
    }

    private function normalizeDates(array $data): array
    {
        foreach (['publishedFrom', 'publishedTo'] as $field) {
            if (!isset($data[$field])) {
                continue;
            }
            $value = $data[$field];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
                throw new ToolCallException($field.': ISO-8601-Datum mit Zeitzone erwartet.');
            }
            try {
                $date = new \DateTimeImmutable($value);
            } catch (\DateMalformedStringException $e) {
                throw new ToolCallException($field.': Ungültiges Datum.', previous: $e);
            }
            $errors = \DateTimeImmutable::getLastErrors();
            if (false !== $errors && ($errors['warning_count'] || $errors['error_count'])) {
                throw new ToolCallException($field.': Ungültiges Datum.');
            }
            $data[$field] = $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i');
        }

        return $data;
    }

    private function summary(News $news): array
    {
        return [
            'id' => $news->getId(),
            'title' => $news->getTitle(),
            'publishedFrom' => $news->getPublishedFrom()?->format(\DateTimeInterface::ATOM),
            'publishedTo' => $news->getPublishedTo()?->format(\DateTimeInterface::ATOM),
            'isActive' => $news->isActive(),
            'hasImage' => !empty($news->getImage()?->getName()),
        ];
    }

    private function details(News $news): array
    {
        $image = $this->uploader->asset($news, 'imageFile');

        return $this->summary($news) + [
            'content' => $news->getContent(),
            'imageUrl' => null !== $image ? $this->urlHelper->getAbsoluteUrl($image) : null,
            'adminEditUrl' => $this->router->generate('admin_news_edit', ['id' => $news->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            'publicUrl' => $this->router->generate('news_detail', ['id' => $news->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ] + $this->history($news);
    }
}
