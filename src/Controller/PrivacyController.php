<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PrivacyController extends AbstractController
{
    #[Route('/politique-de-confidentialite', name: 'app_politique_confidentialite')]
    public function politiqueConfidentialite(): Response
    {
        return $this->render('legal/politique-confidentialite.html.twig');
    }
}
