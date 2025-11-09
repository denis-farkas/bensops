<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

class TestMailController extends AbstractController
{
    #[Route('/test-mail', name: 'app_test_mail')]
    public function testMail(MailerInterface $mailer): Response
    {
        try {
            // Créer un email très simple avec Address explicite
            $email = (new Email())
                ->from(new Address('sne@bensops.fr', 'BENSOPS'))
                ->to(new Address('dfarkas960@gmail.com'))
                ->subject('Test depuis Symfony - ' . date('Y-m-d H:i:s'))
                ->text('Ceci est un test d\'envoi depuis Symfony avec IONOS');
            
            $mailer->send($email);
            
            return new Response('Email envoyé avec succès !', 200);
            
        } catch (\Exception $e) {
            return new Response(
                'Erreur: ' . $e->getMessage() . "\n\n" . 
                'Trace: ' . $e->getTraceAsString(),
                500
            );
        }
    }
}
