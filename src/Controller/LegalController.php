<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class LegalController extends AbstractController
{
    #[Route('/mentions-legales', name: 'app_mentions_legales')]
    public function mentionsLegales(): Response
    {
        return $this->render('legal/mentions-legales.html.twig');
    }
}