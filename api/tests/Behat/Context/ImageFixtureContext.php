<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\When;
use Behat\Testwork\Environment\Environment;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use FriendsOfBehat\SymfonyExtension\Context\Environment\InitializedSymfonyExtensionEnvironment;
use RuntimeException;

/**
 * Seeds an image across BOTH of its stores, which is what makes a scenario able to ask for one at all.
 *
 * **Why the identifier is minted per scenario.** The two stores have different life-cycles: the database is
 * restored per feature from a template, and the object store is a Docker volume no teardown touches — it
 * survives `make docker.down`. A fixture with a fixed identifier is therefore green on the first run and red
 * on the second, because `store()` refuses an identifier that already carries an object. Explicit cleanup
 * and a delete-then-store idempotence were both available and both discarded: they are extra machinery for
 * a problem a unique identity removes outright, and the second can mask a real life-cycle bug by making a
 * leftover object indistinguishable from a fresh one. Database isolation plus a unique object identity gives
 * independence between scenarios even though the object store has no isolation at all. What it costs is
 * stated rather than hidden: the volume accumulates a few hundred bytes per scenario, unpruned.
 *
 * **The bytes go in through the container's own `ImageStorage`**, never by writing a file. Writing one by
 * hand would exercise the test's idea of where the bytes live instead of the deployment's — and the wiring
 * of that root is exactly what was once broken in every environment while ninety tests stayed green, because
 * each had created a root of its own first.
 */
final class ImageFixtureContext extends AbstractContext
{
    /**
     * Short, and not a real image: nothing on the read path decodes bytes. The media type is the row's, and
     * the response declares it whatever the bytes are — a property one of the unit tests pins by storing PNG
     * magic under a `image/webp` row.
     */
    private const string CANONICAL_BYTES = 'behat canonical image bytes';

    private ?ImageId $storedImageId = null;

    private ?Environment $environment = null;

    public function __construct(
        private readonly ImageRepository $images,
        private readonly ImageStorage $storage,
        private readonly TransactionManager $transactionManager,
    ) {
    }

    /**
     * The request context is reached through the scenario's environment rather than injected, because Behat
     * owns when a context is built and hands the same instance to every step of a scenario. Delegating to
     * that instance is what keeps the headers a scenario has already set — `If-None-Match` above all — on
     * the request this context sends.
     *
     * The environment is only CAPTURED here and resolved at the first image step, so a suite whose
     * environment this cannot read fails at the step that needed it rather than in a hook that runs before
     * every scenario in the suite, image or not.
     */
    #[BeforeScenario]
    public function captureTheScenarioEnvironment(BeforeScenarioScope $scope): void
    {
        $this->storedImageId = null;
        $this->environment = $scope->getEnvironment();
    }

    #[Given('there is a stored image with its canonical bytes')]
    public function thereIsAStoredImageWithItsCanonicalBytes(): void
    {
        $image = $this->seedRow();
        $this->storage->store($image->id(), self::CANONICAL_BYTES);
    }

    /**
     * The state the deletion protocol makes reachable: a row nothing can serve. It is also the only way to
     * reach the conditional branch's second gate, which refuses a 304 over an object that is no longer
     * retrievable.
     */
    #[Given('there is an image row whose canonical bytes are gone')]
    public function thereIsAnImageRowWhoseCanonicalBytesAreGone(): void
    {
        $this->seedRow();
    }

    /**
     * Removes the object and leaves the row, mid-scenario, so a conditional request can be made against a
     * validator that is still SYNTACTICALLY current for an image that is no longer servable. Nothing else in
     * the suite can reach that state, and it is the one the double gate exists for.
     */
    #[Given('the canonical bytes of that image are removed from storage')]
    public function theCanonicalBytesOfThatImageAreRemovedFromStorage(): void
    {
        $this->storage->delete($this->identifier());
    }

    #[When('I send a :method request for that image')]
    public function iSendARequestForThatImage(string $method): void
    {
        $this->requestContext()->iSendARequestTo($method, '/images/' . $this->identifier()->toString());
    }

    /**
     * A UUID is case-insensitive as a value and case-sensitive as a string, and the module reconciles the
     * two inside the identifier's constructor. This is the acceptance-level half of that: the route inherits
     * the normalisation rather than sidestepping it.
     */
    #[When('I send a :method request for that image with an upper-cased identifier')]
    public function iSendARequestForThatImageWithAnUpperCasedIdentifier(string $method): void
    {
        $identifier = \strtoupper($this->identifier()->toString());
        $this->requestContext()->iSendARequestTo($method, '/images/' . $identifier);
    }

    private function seedRow(): Image
    {
        $image = new Image(
            ImageId::generate(),
            \hash('sha256', self::CANONICAL_BYTES),
            'image/webp',
            16,
            16,
            \strlen(self::CANONICAL_BYTES),
        );

        $this->transactionManager->transactional(function () use ($image): void {
            $this->images->save($image);
        });

        $this->storedImageId = $image->id();

        return $image;
    }

    private function identifier(): ImageId
    {
        return $this->storedImageId ?? throw new RuntimeException(
            'No image has been seeded in this scenario. Use one of the "there is a … image" steps first.',
        );
    }

    /**
     * Both environment implementations expose `getContext()` and neither declares it on a shared interface —
     * Behat's own and the Symfony extension's, which is the one this suite actually runs under.
     */
    private function requestContext(): HttpRequestContext
    {
        $environment = $this->environment;

        if (
            !$environment instanceof InitializedContextEnvironment
            && !$environment instanceof InitializedSymfonyExtensionEnvironment
        ) {
            throw new RuntimeException(\sprintf(
                'The scenario environment (%s) exposes no contexts, so this step cannot reach the HTTP '
                . 'request context and would send a request without the headers the scenario has set.',
                $environment instanceof Environment ? $environment::class : 'none captured',
            ));
        }

        return $environment->getContext(HttpRequestContext::class);
    }
}
