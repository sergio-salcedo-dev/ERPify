<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain;

use Erpify\Backoffice\BankAccount\Domain\MercureBankAccountTopic;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Locks the stable Mercure topic IRIs the realtime authorization + broadcast paths share. Updates are
 * published to the concrete `forBank`/`forAccount` IRIs while the subscriber cookie is scoped by the
 * `*_TEMPLATE` IRIs, so the concrete topics must fill the same hostname-free namespace or the hub
 * silently drops the update.
 *
 * @internal
 */
#[CoversClass(MercureBankAccountTopic::class)]
final class MercureBankAccountTopicTest extends TestCase
{
    public function testForBankBindsTheConcretePerBankCollectionTopic(): void
    {
        $this->assertSame(
            'urn:erpify:backoffice:bank:11111111-1111-7000-8000-000000000001:accounts',
            MercureBankAccountTopic::forBank('11111111-1111-7000-8000-000000000001'),
        );
    }

    public function testForAccountBindsTheConcretePerAccountDetailTopic(): void
    {
        $this->assertSame(
            'urn:erpify:backoffice:bankaccount:33333333-3333-7000-8000-000000000001',
            MercureBankAccountTopic::forAccount('33333333-3333-7000-8000-000000000001'),
        );
    }

    public function testEveryAuthorizedTopicIsADistinctHostnameFreeBackofficeUrn(): void
    {
        $topics = [
            MercureBankAccountTopic::COLLECTION,
            MercureBankAccountTopic::COLLECTION_TEMPLATE,
            MercureBankAccountTopic::DETAIL_TEMPLATE,
        ];

        foreach ($topics as $topic) {
            $this->assertStringStartsWith('urn:erpify:backoffice:', $topic);
        }

        $this->assertCount(3, \array_unique($topics), 'the three authorization scopes must be distinct');
    }
}
