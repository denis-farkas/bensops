<?php
// src/Command/CreatePackCommand.php
namespace App\Command;

use App\Entity\Rdv;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


class CreateRdvCommand extends Command
{
    protected static $defaultName = 'app:create-rdv';
    protected static $defaultDescription = 'Create rdv';

    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->setName('app:create-rdv');
            
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rdvData = [
            ['name' => 'rendez-vous', 'price' => 50, 'duration' => 30],
        ];

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($rdvData as $data) {
            // Vérifier si un RDV avec ce nom existe déjà
            $existingRdv = $this->entityManager
                ->getRepository(Rdv::class)
                ->findOneBy(['name' => $data['name']]);

            if ($existingRdv) {
                // ÉCRASER l'ancien RDV avec les nouvelles valeurs
                $existingRdv->setPrice($data['price']);
                $existingRdv->setDuration($data['duration']);
                $io->note("RDV '{$data['name']}' mis à jour - Prix: {$data['price']}€, Durée: {$data['duration']}min");
                $skippedCount++;
            } else {
                // Créer un nouveau RDV s'il n'existe pas
                $rdv = new Rdv();
                $rdv->setName($data['name']);           
                $rdv->setPrice($data['price']);
                $rdv->setDuration($data['duration']);
                
                $this->entityManager->persist($rdv);
                $io->note("Nouveau RDV '{$data['name']}' créé - Prix: {$data['price']}€, Durée: {$data['duration']}min");
                $createdCount++;
            }
        }

        // Sauvegarder les modifications (création OU mise à jour)
        $this->entityManager->flush();

        $io->success("Résultat : $createdCount RDV créé(s), $skippedCount RDV mis à jour");

        return Command::SUCCESS;
    }
}