<?php

namespace App\Controller;

use App\Document\MatchQueryResult;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\UuidV7;

final class MatchController extends AbstractController
{
    public function __construct(private readonly DocumentManager $dm) {}

    #[Route('/match/{id}', name: 'match')]
    public function index(UuidV7 $id): Response
    {
        /** @var MatchQueryResult|null $result */
        $result = $this->dm->getRepository(MatchQueryResult::class)->find($id);

        // Results carry a one-hour TTL index, so a shared link does expire.
        if (null === $result) {
            throw new NotFoundHttpException('That search has expired.');
        }

        return $this->render('match.html.twig', [
            'match' => $result->closest(),
            'candidates' => $result->runnersUp(),
            'description' => $result->objectDescription,
        ]);
    }
}
