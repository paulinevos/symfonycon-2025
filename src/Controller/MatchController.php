<?php

namespace App\Controller;

use App\Document\Artefact;
use App\Document\MatchQueryResult;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\UuidV7;

final class MatchController extends AbstractController
{
    public function __construct(private readonly DocumentManager $dm) {}

    #[Route('/match/{id}', name: 'match')]
    public function index(UuidV7 $id): Response
    {
        $repository = $this->dm->getRepository(MatchQueryResult::class);

        /** @var MatchQueryResult $result */
        $result = $repository->find($id);

        $candidates = $result->matches->toArray();
        $match = array_pop($candidates);

        return $this->render('match.html.twig', [
            'match' => $match,
            'candidates' => $candidates,
        ]);
    }
}
