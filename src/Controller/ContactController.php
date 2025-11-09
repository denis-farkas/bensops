<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

final class ContactController extends AbstractController
{
    #[Route('/contact', name: 'app_contact')]
    public function index(): Response
    {
        return $this->render('contact/index.html.twig', [
            'controller_name' => 'ContactController',
        ]);
    }
    
    #[Route('/contact/send', name: 'contact_send', methods: ['POST'])]
    public function send(Request $request, MailerInterface $mailer): Response
    {
        // 1. Récupérer les données du formulaire
        $nom = $request->request->get('nom');
        $userEmail = $request->request->get('email');
        $sujet = $request->request->get('sujet');
        $message = $request->request->get('message');
        $rgpd = $request->request->has('rgpd');
        
        // 2. Validation basique
        $errors = [];
        if (!$nom) $errors[] = 'Le nom est requis';
        if (!$userEmail || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalide';
        if (!$sujet) $errors[] = 'Le sujet est requis';
        if (!$message) $errors[] = 'Le message est requis';
        if (!$rgpd) $errors[] = 'Vous devez accepter la politique de confidentialité';
        
        // 3. Si erreurs, retourner à la page de contact avec messages d'erreur
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
            return $this->redirectToRoute('app_contact');
        }
        
        // 4. Envoyer l'email
        try {
            // Email à BENSOPS
            $email = (new Email())
                ->from(new Address('sne@bensops.fr', 'BENSOPS'))
                ->replyTo($userEmail)
                ->to(new Address('sne@bensops.fr'))
                ->subject('Formulaire de contact: ' . $sujet)
                ->text("Message de: {$nom} ({$userEmail})\n\n{$message}")
                ->html("
                    <h3>Nouveau message du formulaire de contact</h3>
                    <p><strong>De:</strong> {$nom}</p>
                    <p><strong>Email:</strong> {$userEmail}</p>
                    <p><strong>Sujet:</strong> {$sujet}</p>
                    <p><strong>Message:</strong></p>
                    <p>" . nl2br(htmlspecialchars($message)) . "</p>
                ");
            
            error_log("Envoi email BENSOPS à sne@bensops.fr");
            $mailer->send($email);
            error_log("Email BENSOPS envoyé avec succès");
            
            // Email de confirmation au client
            $confirmationEmail = (new Email())
                ->from(new Address('sne@bensops.fr', 'BENSOPS'))
                ->to(new Address($userEmail, $nom))
                ->subject('Confirmation de réception - ' . $sujet)
                ->html("
                    <h3>Merci pour votre message</h3>
                    <p>Bonjour {$nom},</p>
                    <p>Nous avons bien reçu votre message et nous vous remercions de nous avoir contactés.</p>
                    <p>Nous reviendrons vers vous dans les plus brefs délais.</p>
                    <hr>
                    <p><strong>Récapitulatif de votre message :</strong></p>
                    <p><strong>Sujet :</strong> {$sujet}</p>
                    <p><strong>Message :</strong></p>
                    <p>" . nl2br(htmlspecialchars($message)) . "</p>
                    <hr>
                    <p>Cordialement,<br>L'équipe BENSOPS</p>
                ");
            
            error_log("Envoi email confirmation à $userEmail");
            $mailer->send($confirmationEmail);
            error_log("Email confirmation envoyé avec succès à $userEmail");
            
            // 5. Message de confirmation
            $this->addFlash('success', 'Votre message a été envoyé avec succès. Un email de confirmation vous a été envoyé.');
            
        } catch (\Exception $e) {
            // 6. Gestion des erreurs
            error_log('Erreur envoi email contact: ' . $e->getMessage());
            $this->addFlash('error', 'Une erreur est survenue lors de l\'envoi du message: ' . $e->getMessage());
        }
        
        // 7. Redirection
        return $this->redirectToRoute('app_contact');
    }
}