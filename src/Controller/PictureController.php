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

    #[Route('/photo/submit', name: 'photo_sumbit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $query = new MatchQuery($data['prompt'] ?? null, $data['image'] ?? null);

        foreach ($query->images as $image) {
            if (strlen($image->asBinary()) / (1024 * 1024) >= 20) {
                return new JsonResponse(['error' => 'File is too large.']);
            }
        }

        try {
            $result = $this->handler->__invoke($query);

            return new JsonResponse([
                'message' => 'Picture processed successfully',
                'url' => $this->generateUrl('match', [
                    'id' => $result->id,
                ], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Something went wrong: ', $e->getMessage()], 500);
        }
    }
}
