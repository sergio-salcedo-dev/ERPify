<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509111104 extends AbstractMigration
{
    /**
     * @var array<int, array{id: string, name: string, shortName: string}>
     */
    private const array SEED_BANKS = [
        ['id' => 'b0000001-0000-4000-8000-000000000001', 'name' => 'JPMorgan Chase', 'shortName' => 'JPM'],
        ['id' => 'b0000001-0000-4000-8000-000000000002', 'name' => 'Bank of America', 'shortName' => 'BAC'],
        ['id' => 'b0000001-0000-4000-8000-000000000003', 'name' => 'Wells Fargo', 'shortName' => 'WFC'],
        ['id' => 'b0000001-0000-4000-8000-000000000004', 'name' => 'Citigroup', 'shortName' => 'C'],
        ['id' => 'b0000001-0000-4000-8000-000000000005', 'name' => 'Goldman Sachs', 'shortName' => 'GS'],
        ['id' => 'b0000001-0000-4000-8000-000000000006', 'name' => 'Morgan Stanley', 'shortName' => 'MS'],
        ['id' => 'b0000001-0000-4000-8000-000000000007', 'name' => 'HSBC Holdings', 'shortName' => 'HSBC'],
        ['id' => 'b0000001-0000-4000-8000-000000000008', 'name' => 'Barclays', 'shortName' => 'BARC'],
        ['id' => 'b0000001-0000-4000-8000-000000000009', 'name' => 'Lloyds Banking Group', 'shortName' => 'LLOY'],
        ['id' => 'b0000001-0000-4000-8000-000000000010', 'name' => 'NatWest Group', 'shortName' => 'NWG'],
        ['id' => 'b0000001-0000-4000-8000-000000000011', 'name' => 'Standard Chartered', 'shortName' => 'STAN'],
        ['id' => 'b0000001-0000-4000-8000-000000000012', 'name' => 'Deutsche Bank', 'shortName' => 'DB'],
        ['id' => 'b0000001-0000-4000-8000-000000000013', 'name' => 'Commerzbank', 'shortName' => 'CBK'],
        ['id' => 'b0000001-0000-4000-8000-000000000014', 'name' => 'BNP Paribas', 'shortName' => 'BNP'],
        ['id' => 'b0000001-0000-4000-8000-000000000015', 'name' => 'Société Générale', 'shortName' => 'GLE'],
        ['id' => 'b0000001-0000-4000-8000-000000000016', 'name' => 'Crédit Agricole', 'shortName' => 'ACA'],
        ['id' => 'b0000001-0000-4000-8000-000000000017', 'name' => 'UBS Group', 'shortName' => 'UBSG'],
        ['id' => 'b0000001-0000-4000-8000-000000000018', 'name' => 'ING Group', 'shortName' => 'INGA'],
        ['id' => 'b0000001-0000-4000-8000-000000000019', 'name' => 'Banco Santander', 'shortName' => 'SAN'],
        ['id' => 'b0000001-0000-4000-8000-000000000020', 'name' => 'BBVA', 'shortName' => 'BBVA'],
        ['id' => 'b0000001-0000-4000-8000-000000000021', 'name' => 'CaixaBank', 'shortName' => 'CABK'],
        ['id' => 'b0000001-0000-4000-8000-000000000022', 'name' => 'Banco Sabadell', 'shortName' => 'SAB'],
        ['id' => 'b0000001-0000-4000-8000-000000000023', 'name' => 'Bankinter', 'shortName' => 'BKT'],
        ['id' => 'b0000001-0000-4000-8000-000000000024', 'name' => 'UniCredit', 'shortName' => 'UCG'],
        ['id' => 'b0000001-0000-4000-8000-000000000025', 'name' => 'Intesa Sanpaolo', 'shortName' => 'ISP'],
        ['id' => 'b0000001-0000-4000-8000-000000000026', 'name' => 'Nordea Bank', 'shortName' => 'NDA'],
        ['id' => 'b0000001-0000-4000-8000-000000000027', 'name' => 'Royal Bank of Canada', 'shortName' => 'RY'],
        ['id' => 'b0000001-0000-4000-8000-000000000028', 'name' => 'Toronto-Dominion Bank', 'shortName' => 'TD'],
        ['id' => 'b0000001-0000-4000-8000-000000000029', 'name' => 'Mitsubishi UFJ Financial Group', 'shortName' => 'MUFG'],
        ['id' => 'b0000001-0000-4000-8000-000000000030', 'name' => 'Industrial and Commercial Bank of China', 'shortName' => 'ICBC'],
    ];

    public function getDescription(): string
    {
        return 'Seed common banks';
    }

    public function up(Schema $schema): void
    {
        $now = '2026-05-09 00:00:00';

        foreach (self::SEED_BANKS as $bank) {
            $this->addSql(
                'INSERT INTO bank (id, name, short_name, created_at, updated_at)
                 VALUES (:id, :name, :short_name, :created_at, :updated_at)
                 ON CONFLICT (id) DO NOTHING',
                [
                    'id' => $bank['id'],
                    'name' => $bank['name'],
                    'short_name' => $bank['shortName'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $ids = \array_column(self::SEED_BANKS, 'id');
        $this->addSql(
            'DELETE FROM bank WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
    }
}
