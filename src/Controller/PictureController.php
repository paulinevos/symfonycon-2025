<?php

declare(strict_types=1);

namespace App\Controller;

use App\Query\MatchQuery;
use App\Query\MatchQueryHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PictureController extends AbstractController
{
    public function __construct(private readonly MatchQueryHandler $handler)
    {
    }

    #[Route('/search', name: 'search')]
    public function index(): Response
    {
        return $this->render('search.html.twig');
    }

    #[Route('/photo/submit', name: 'photo_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $prompt = trim($data['prompt'] ?? '');

        if ('' === $prompt) {
            return new JsonResponse(['error' => 'Describe what you are looking for.'], 422);
        }

        try {
            $result = $this->handler->__invoke(new MatchQuery($prompt));

            return new JsonResponse([
                'message' => 'Description processed successfully',
                'url' => $this->generateUrl('match', [
                    'id' => $result->id,
                ], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Something went wrong: ' . $e->getMessage()], 500);
        }
    }
}
