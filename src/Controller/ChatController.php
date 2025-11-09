<?php 
// src/Controller/ChatController.php
namespace App\Controller;

use Lcobucci\JWT\Configuration;
use Symfony\Component\HttpFoundation\Cookie;
use App\Entity\Message;
use App\Entity\Room;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class ChatController extends AbstractController
{

// Dans la méthode chatSession de ChatController.php
#[Route('/chat/session', name: 'chat_session', methods: ['GET'])]
    public function chatSession(
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer
    ): Response {
        try {
            error_log('chat_session request: ' . ($request->isXmlHttpRequest() ? 'XHR' : 'standard'));
            // Si c'est un admin, le rediriger vers l'interface d'administration
            if ($this->isGranted('ROLE_ADMIN')) {
                return $this->redirectToRoute('admin_chat_rooms');
            }
            
            // 1. Identification ou création de la room dédiée
            $isNewRoom = false;
            $room = null;
            $requestedRoomId = $request->query->getInt('roomId', 0);
            $requestedToken = $request->query->get('roomToken');
            $roomRequestedButInvalid = false;

            if ((!$requestedRoomId || !$requestedToken) && !$this->isGranted('ROLE_ADMIN')) {
                $cookiePayload = $request->cookies->get('anon_chat_session');
                if ($cookiePayload && str_contains($cookiePayload, ':')) {
                    [$cookieRoomId, $cookieToken] = explode(':', $cookiePayload, 2);
                    if ($cookieRoomId && $cookieToken) {
                        $requestedRoomId = (int) $cookieRoomId;
                        $requestedToken = $cookieToken;
                    }
                }
            }

            if ($requestedRoomId > 0 && !empty($requestedToken)) {
                $existingRoom = $entityManager->getRepository(Room::class)->find($requestedRoomId);
                if ($existingRoom && hash_equals($this->generateRoomToken($existingRoom->getId()), $requestedToken)) {
                    $room = $existingRoom;
                } else {
                    $roomRequestedButInvalid = true;
                }
            }

            if (!$room) {
                if ($request->isXmlHttpRequest() && $roomRequestedButInvalid) {
                    $response = new JsonResponse(['error' => 'conversation_not_found'], 404);
                    $response->headers->clearCookie('anon_chat_session', '/');
                    return $response;
                }

                $room = new Room();
                $room->setName('Discussion invité ' . uniqid());
                $entityManager->persist($room);
                $entityManager->flush();
                $isNewRoom = true;
            }

            $roomToken = $this->generateRoomToken($room->getId());

            // 2. Les visiteurs gèrent désormais leur nom côté client
            $userName = '';
            $hasUsername = false;

            // 3. Tenter d'envoyer un email mais continuer même en cas d'erreur
            try {
                $emailSubject = $isNewRoom ? 'Nouvelle conversation ouverte' : 'Conversation reprise';
                $emailMessage = $isNewRoom 
                    ? "Un visiteur a ouvert une nouvelle conversation (Room #{$room->getId()})"
                    : "Un visiteur a repris une conversation existante (Room #{$room->getId()})";
                
                if ($userName) {
                    $emailMessage .= " - Nom: $userName";
                }
                
                $email = (new Email())
                    ->from(new Address('sne@bensops.fr', 'BENSOPS'))
                    ->to(new Address('sne@bensops.fr'))
                    ->subject($emailSubject)
                    ->text($emailMessage);
                
                $mailer->send($email);
            } catch (\Exception $e) {
                // Ignorer les erreurs d'email et continuer
                error_log('Erreur envoi email: ' . $e->getMessage());
            }

            // 4. Configuration JWT pour Mercure - avec gestion d'erreur
            try {
                $jwtSecret = $_ENV['MERCURE_JWT_SECRET'] ?? '!ChangeThisMercureHubJWTSecretKey!';
                $config = Configuration::forSymmetricSigner(
                    new Sha256(),
                    InMemory::plainText($jwtSecret)
                );
                $token = $config->builder()
                    ->withClaim('mercure', ['subscribe' => ["/chat/{$room->getId()}"]])
                    ->getToken($config->signer(), $config->signingKey());
                $jwt = $token->toString();
            } catch (\Exception $e) {
                // En cas d'erreur JWT, utiliser une chaîne vide
                error_log('Erreur JWT: ' . $e->getMessage());
                $jwt = '';
            }

            // 5. Récupération des messages de cette room
            $messages = $entityManager->getRepository(Message::class)
                ->findBy(['roomId' => $room->getId()], ['timestamp' => 'ASC']);
            $mercurePublicUrl = $_ENV['MERCURE_PUBLIC_URL'] ?? 'http://127.0.0.1:3000/.well-known/mercure';

            // 6. Rendu du template (modal en AJAX, page complète sinon)
            $template = $request->isXmlHttpRequest()
                ? 'chat/_modal_content.html.twig'
                : 'chat/index.html.twig';

            $response = $this->render($template, [
                'messages' => $messages,
                'roomId' => $room->getId(),
                'mercure_public_url' => $mercurePublicUrl,
                'userName' => $userName,
                'hasUsername' => $hasUsername,
                'roomToken' => $roomToken,
                'isAdminContext' => false
            ]);
            
            // Ajouter le cookie seulement si JWT est valide
            if ($jwt) {
                $response->headers->setCookie(
                    Cookie::create('mercureAuthorization', $jwt)
                        ->withHttpOnly(true)
                        ->withPath('/.well-known/mercure')
                );
            }

            if (!$this->isGranted('ROLE_ADMIN')) {
                $sessionCookie = Cookie::create('anon_chat_session', $room->getId() . ':' . $roomToken)
                    ->withPath('/')
                    ->withExpires(new \DateTimeImmutable('+30 days'))
                    ->withHttpOnly(true);
                $response->headers->setCookie($sessionCookie);
            }
            
            return $response;
        } catch (\Exception $e) {
            // Log l'erreur pour debugging
            error_log('Erreur dans chat_session: ' . $e->getMessage());
            
            // Retourner une réponse d'erreur personnalisée
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'error' => 'Une erreur est survenue',
                    'details' => $e->getMessage(),
                ], 500);
            }
            
            // Pour requêtes normales, page d'erreur simple
            return new Response(
                '<html><body><h1>Erreur de chargement du chat</h1><p>Veuillez réessayer ultérieurement.</p></body></html>',
                500
            );
        }
    }
    #[Route('/chat/{roomId<\d+>}', name: 'chat_private', methods: ['GET'])]
    public function privateChat(
        int $roomId,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        // Seul l'admin peut accéder à cette interface
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas accéder à cette conversation.');
        }

        $room = $entityManager->getRepository(Room::class)->find($roomId);
        if (!$room) {
            throw $this->createNotFoundException('Conversation introuvable.');
        }

        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($_ENV['MERCURE_JWT_SECRET'])
        );
        $token = $config->builder()
            ->withClaim('mercure', ['subscribe' => ["/chat/{$roomId}"]])
            ->getToken($config->signer(), $config->signingKey());
        $jwt = $token->toString();

        $messages = $entityManager->getRepository(Message::class)
            ->findBy(['roomId' => $roomId], ['timestamp' => 'ASC']);
        $mercurePublicUrl = $_ENV['MERCURE_PUBLIC_URL'] ?? $_SERVER['MERCURE_PUBLIC_URL'] ?? '';

        $response = $this->render('chat/index.html.twig', [
            'messages' => $messages,
            'roomId' => $roomId,
            'mercure_public_url' => $mercurePublicUrl,
            'userName' => 'Admin',
            'hasUsername' => true,
            'roomToken' => null,
            'isAdminContext' => true
        ]);
        
        $response->headers->setCookie(
            Cookie::create('mercureAuthorization', $jwt)
                ->withHttpOnly(true)
                ->withPath('/.well-known/mercure')
        );
        
        return $response;
    }

    #[Route('/chat/send/{roomId}', name: 'chat_send', methods: ['POST'])]
    public function send(
        int $roomId,
        Request $request,
        EntityManagerInterface $entityManager,
        HubInterface $hub,
        MailerInterface $mailer
    ): Response {
        // Validation CSRF pour les admins
        if ($this->isGranted('ROLE_ADMIN')) {
            if (!$this->isCsrfTokenValid('chat_message', $request->request->get('_token'))) {
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(['error' => 'Token CSRF invalide'], 403);
                }
                throw $this->createAccessDeniedException('Token CSRF invalide.');
            }
        }
        
        $room = $entityManager->getRepository(Room::class)->find($roomId);
        if (!$room) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Conversation introuvable'], 404);
            }
            throw $this->createNotFoundException('Conversation introuvable');
        }

        $content = $request->request->get('message');
        $sender = $request->request->get('username');
        $roomToken = $request->request->get('roomToken');

        // Si c'est un admin, forcer le nom à "Admin"
        if ($this->isGranted('ROLE_ADMIN')) {
            $sender = 'Admin';
        } else {
            $expectedToken = $this->generateRoomToken($room->getId());
            if (!$roomToken || !hash_equals($expectedToken, $roomToken)) {
                if ($request->isXmlHttpRequest()) {
                    $response = new JsonResponse(['error' => 'conversation_not_found'], 403);
                    $response->headers->clearCookie('anon_chat_session', '/');
                    return $response;
                }
                $response = $this->redirectToRoute('chat_session');
                $response->headers->clearCookie('anon_chat_session', '/');
                return $response;
            }
        }

        if (!$content || !$sender) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Missing data'], 400);
            }
            return $this->redirectToRoute($this->isGranted('ROLE_ADMIN') ? 'chat_private' : 'chat_session', ['roomId' => $roomId]);
        }

        $message = new Message();
        $message->setContent($content);
        $message->setSender($sender);
        $message->setTimestamp(new \DateTime());
        $message->setRoomId($roomId);

        $entityManager->persist($message);
        $entityManager->flush();

        // Envoyer un email de notification si c'est un message de visiteur (non-admin)
        if (!$this->isGranted('ROLE_ADMIN')) {
            $this->sendEmailNotification($mailer, $message, $roomId);
        }

        // Essayer de publier via Mercure avec gestion d'erreur
        $mercureSuccess = false;
        try {
            $update = new Update(
                "/chat/{$roomId}",
                json_encode([
                    'id' => $message->getId(),
                    'content' => $message->getContent(),
                    'sender' => $message->getSender(),
                    'timestamp' => $message->getTimestamp()->format('Y-m-d H:i:s')
                ]),
                true
            );
            $result = $hub->publish($update);
            $mercureSuccess = true;
            error_log('Mercure publish success: ' . $result);
        } catch (\Exception $e) {
            // Log l'erreur mais continuer
            error_log('Erreur Mercure publish: ' . $e->getMessage());
            error_log('Mercure config - URL: ' . ($_ENV['MERCURE_URL'] ?? 'non défini'));
            error_log('Mercure config - Secret: ' . (isset($_ENV['MERCURE_JWT_SECRET']) ? 'défini' : 'non défini'));
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => true,
                'mercure_status' => $mercureSuccess ? 'published' : 'failed',
                'message_id' => $message->getId()
            ]);
        }
        
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('chat_private', ['roomId' => $roomId]);
        } else {
            return $this->redirectToRoute('chat_session');
        }
    }

    #[Route('/admin/chat-rooms', name: 'admin_chat_rooms', methods: ['GET'])]
    public function listRooms(EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
        
        // Récupérer toutes les rooms avec leurs messages
        $rooms = $entityManager->getRepository(Room::class)->findAll();
        
        // Pour chaque room, récupérer ses messages
        $roomsWithMessages = [];
        foreach ($rooms as $room) {
            $messages = $entityManager->getRepository(Message::class)
                ->findBy(['roomId' => $room->getId()], ['timestamp' => 'ASC']);
            
            // Ne garder que les rooms qui ont des messages
            if (!empty($messages)) {
                // Créer un objet avec la room et ses messages
                $roomData = (object) [
                    'id' => $room->getId(),
                    'name' => $room->getName(),
                    'messages' => $messages,
                    // Ajouter des statistiques utiles
                    'messageCount' => count($messages),
                    'lastMessage' => end($messages),
                    'hasUnreadAdmin' => $this->hasUnreadAdminMessages($messages),
                ];
                
                $roomsWithMessages[] = $roomData;
            }
        }
        
        // Trier par dernière activité (rooms avec messages récents en premier)
        usort($roomsWithMessages, function($a, $b) {
            if ($a->lastMessage && $b->lastMessage) {
                return $b->lastMessage->getTimestamp() <=> $a->lastMessage->getTimestamp();
            }
            if ($a->lastMessage) return -1;
            if ($b->lastMessage) return 1;
            return $b->id <=> $a->id; // Par ID décroissant si pas de messages
        });
        
        return $this->render('admin/chat_rooms.html.twig', [
            'rooms' => $roomsWithMessages,
        ]);
    }
    
    private function hasUnreadAdminMessages(array $messages): bool
    {
        // Logique pour déterminer s'il y a des messages non lus par l'admin
        // Pour l'instant, on considère qu'il y a des messages non lus s'il y a 
        // des messages de visiteurs après le dernier message admin
        $lastAdminMessageTime = null;
        $hasUserMessagesAfter = false;
        
        foreach ($messages as $message) {
            if ($message->getSender() === 'Admin') {
                $lastAdminMessageTime = $message->getTimestamp();
                $hasUserMessagesAfter = false;
            } elseif ($lastAdminMessageTime === null || $message->getTimestamp() > $lastAdminMessageTime) {
                $hasUserMessagesAfter = true;
            }
        }
        
        return $hasUserMessagesAfter;
    }
    
    #[Route('/admin/dashboard', name: 'admin_dashboard', methods: ['GET'])]
    public function adminDashboard(EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
        
        // Statistiques rapides pour le dashboard
        $totalRooms = $entityManager->getRepository(Room::class)->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
            
        $totalMessages = $entityManager->getRepository(Message::class)->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
            
        $messagesAdmin = $entityManager->getRepository(Message::class)->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.sender = :sender')
            ->setParameter('sender', 'Admin')
            ->getQuery()
            ->getSingleScalarResult();
            
        $messagesUsers = $totalMessages - $messagesAdmin;
        
        // Conversations récentes (dernières 24h)
        $recentMessages = $entityManager->getRepository(Message::class)->createQueryBuilder('m')
            ->where('m.timestamp >= :since')
            ->setParameter('since', new \DateTime('-24 hours'))
            ->orderBy('m.timestamp', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
        
        return $this->render('admin/dashboard.html.twig', [
            'totalRooms' => $totalRooms,
            'totalMessages' => $totalMessages,
            'messagesAdmin' => $messagesAdmin,
            'messagesUsers' => $messagesUsers,
            'recentMessages' => $recentMessages,
        ]);
    }

    private function generateRoomToken(int $roomId): string
    {
        $secret = $_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? 'fallback-chat-secret';
        return hash_hmac('sha256', 'room-' . $roomId, $secret);
    }
    
    private function sendEmailNotification(MailerInterface $mailer, Message $message, int $roomId): void
    {
        try {
            // Récupérer les informations de la room si disponible
            $roomInfo = "Room #$roomId";
            
            // Créer le contenu de l'email
            $emailSubject = "💬 Nouveau message de chat - $roomInfo";
            $emailContent = "
            <h2>Nouveau message reçu</h2>
            <p><strong>Conversation:</strong> $roomInfo</p>
            <p><strong>Expéditeur:</strong> {$message->getSender()}</p>
            <p><strong>Date:</strong> {$message->getTimestamp()->format('d/m/Y à H:i:s')}</p>
            <p><strong>Message:</strong></p>
            <blockquote style='background: #f8f9fa; padding: 15px; border-left: 4px solid #007bff; margin: 10px 0;'>
                {$message->getContent()}
            </blockquote>
            <hr>
            <p><strong>Actions:</strong></p>
            <ul>
                <li>Répondre depuis l'admin: <a href='" . $_SERVER['HTTP_HOST'] . "/admin/chat-rooms'>Interface Admin</a></li>
                <li>Voir la conversation: <a href='" . $_SERVER['HTTP_HOST'] . "/chat/$roomId'>Conversation directe</a></li>
            </ul>
            <p><em>Message envoyé automatiquement par le système de chat.</em></p>
            ";
            
             $adminEmail = $_ENV['ADMIN_EMAIL'] ?? 'sne@bensops.fr';
            
            $email = (new Email())
                ->from(new Address('sne@bensops.fr', 'BENSOPS'))
                ->to(new Address($adminEmail))
                ->subject($emailSubject)
                ->html($emailContent);
                
            $mailer->send($email);
            
            error_log("Email de notification envoyé pour le message de {$message->getSender()} dans la room $roomId");
            
        } catch (\Exception $e) {
            // En cas d'erreur d'email, on log mais on ne fait pas planter l'application
            error_log('Erreur envoi email de notification: ' . $e->getMessage());
        }
    }
    
    #[Route('/admin/message/{id}/delete', name: 'admin_delete_message', methods: ['POST', 'DELETE'])]
    public function deleteMessage(
        int $id,
        EntityManagerInterface $entityManager,
        Request $request
    ): Response {
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
        
        $message = $entityManager->getRepository(Message::class)->find($id);
        if (!$message) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Message non trouvé'], 404);
            }
            throw $this->createNotFoundException('Message non trouvé');
        }
        
        $roomId = $message->getRoomId();
        
        try {
            $entityManager->remove($message);
            $entityManager->flush();
            
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => true, 'message' => 'Message supprimé']);
            }
            
            $this->addFlash('success', 'Message supprimé avec succès');
            
        } catch (\Exception $e) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Erreur lors de la suppression'], 500);
            }
            $this->addFlash('error', 'Erreur lors de la suppression du message');
        }
        
        return $this->redirectToRoute('admin_chat_rooms');
    }
    
    #[Route('/admin/room/{id}/delete', name: 'admin_delete_room', methods: ['POST', 'DELETE'])]
    public function deleteRoom(
        int $id,
        EntityManagerInterface $entityManager,
        Request $request
    ): Response {
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
        
        $room = $entityManager->getRepository(Room::class)->find($id);
        if (!$room) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Conversation non trouvée'], 404);
            }
            throw $this->createNotFoundException('Conversation non trouvée');
        }
        
        try {
            // Supprimer d'abord tous les messages de cette room
            $messages = $entityManager->getRepository(Message::class)
                ->findBy(['roomId' => $room->getId()]);
            
            foreach ($messages as $message) {
                $entityManager->remove($message);
            }
            
            // Puis supprimer la room
            $entityManager->remove($room);
            $entityManager->flush();
            
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => true, 'message' => 'Conversation supprimée']);
            }
            
            $this->addFlash('success', 'Conversation supprimée avec succès');
            
        } catch (\Exception $e) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Erreur lors de la suppression'], 500);
            }
            $this->addFlash('error', 'Erreur lors de la suppression de la conversation');
        }
        
        return $this->redirectToRoute('admin_chat_rooms');
    }
}