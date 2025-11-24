<?php

namespace App\Controller;

use App\Document\Artefact;
use App\Document\MatchQueryResult;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\UuidV7;

final class HomeController extends AbstractController
{
    public function __construct(private readonly DocumentManager $dm) {}

    #[Route('/', name: 'home')]
    public function index(): Response
    {
        return $this->render('index.html.twig');
    }
}
