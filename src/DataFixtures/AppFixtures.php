<?php

namespace App\DataFixtures;

use App\Entity\Administrateur;
use App\Entity\Theatre;
use App\Entity\Ouvreur;
use App\Entity\Pourboire;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{

    private UserPasswordHasherInterface $userPasswordHasher;

    public function __construct(UserPasswordHasherInterface $userPasswordHasher)
    {
        $this->userPasswordHasher = $userPasswordHasher;
    }


    public function load(ObjectManager $manager): void
    {
        // Création du premier administrateur
        $admin = new Administrateur();
        $admin->setNom('Nom-Admin');
        $admin->setPrenom('Prenom-Admin');
        $admin->setEmail('premieradmin@gmail.com'); 

        $hashedPassword = $this->userPasswordHasher->hashPassword(
            $admin,
            'password123' 
        );
        $admin->setPassword($hashedPassword);
      
        $admin->setRoles(['ROLE_ADMIN']); 

        $manager->persist($admin);
        $manager->flush();

        /* commande pour mettre dans la bdd : php bin/console doctrine:fixtures:load */
    }
}
