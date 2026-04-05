<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Symfony\Component\Uid\Uuid;

final class BankFixture extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $banks = [
            ['Santander', 'SAN'],
            ['BBVA', 'BBVA'],
            ['CaixaBank', 'CAIXA'],
        ];

        foreach ($banks as [$name, $shortName]) {
            $manager->persist(Bank::create(Uuid::v4(), $name, $shortName));
        }

        $manager->flush();
    }
}
