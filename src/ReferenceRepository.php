<?php

declare(strict_types=1);

namespace Doctrine\Common\DataFixtures;

use BadMethodCallException;
use Doctrine\ODM\PHPCR\DocumentManager as PhpcrDocumentManager;
use Doctrine\ORM\UnitOfWork as OrmUnitOfWork;
use Doctrine\Persistence\ObjectManager;
use OutOfBoundsException;

use function array_key_exists;
use function array_keys;
use function sprintf;

/**
 * ReferenceRepository class manages references for
 * fixtures in order to easily support the relations
 * between fixtures
 */
class ReferenceRepository
{
    /**
     * List of named references to the fixture objects
     * gathered during fixure loading
     *
     * @psalm-var array<class-string, array<string, object>>
     */
    private array $referencesByClass = [];

    /**
     * List of identifiers stored for references
     * in case a reference gets no longer managed, it will
     * use a proxy referenced by this identity
     *
     * @psalm-var array<class-string, array<string, mixed>>
     */
    private array $identitiesByClass = [];

    /**
     * Currently used object manager
     */
    private ObjectManager $manager;

    public function __construct(ObjectManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Get identifier for a unit of work
     *
     * @param object $reference Reference object
     * @param object $uow       Unit of work
     *
     * @return array
     */
    protected function getIdentifier(object $reference, object $uow)
    {
        // In case Reference is not yet managed in UnitOfWork
        if (! $this->hasIdentifier($reference)) {
            $class = $this->manager->getClassMetadata($reference::class);

            return $class->getIdentifierValues($reference);
        }

        // Dealing with ORM UnitOfWork
        if ($uow instanceof OrmUnitOfWork) {
            return $uow->getEntityIdentifier($reference);
        }

        // PHPCR ODM UnitOfWork
        if ($this->manager instanceof PhpcrDocumentManager) {
            return $uow->getDocumentId($reference);
        }

        // ODM UnitOfWork
        return $uow->getDocumentIdentifier($reference);
    }

    /**
     * Set the reference entry identified by $name
     * and referenced to $reference. If $name
     * already is set, it overrides it
     *
     * @return void
     */
    public function setReference(string $name, object $reference)
    {
        $class = $this->getRealClass($reference::class);

        $this->referencesByClass[$class][$name] = $reference;

        if (! $this->hasIdentifier($reference)) {
            return;
        }

        // in case if reference is set after flush, store its identity
        $uow        = $this->manager->getUnitOfWork();
        $identifier = $this->getIdentifier($reference, $uow);

        $this->identitiesByClass[$class][$name] = $identifier;
    }

    /**
     * Store the identifier of a reference
     *
     * @param mixed        $identity
     * @param class-string $class
     *
     * @return void
     */
    public function setReferenceIdentity(string $name, $identity, string $class)
    {
        $this->identitiesByClass[$class][$name] = $identity;
    }

    /**
     * Set the reference entry identified by $name
     * and referenced to managed $object. $name must
     * not be set yet
     *
     * Notice: in case if identifier is generated after
     * the record is inserted, be sure tu use this method
     * after $object is flushed
     *
     * @param object $object - managed object
     *
     * @return void
     *
     * @throws BadMethodCallException - if repository already has a reference by $name.
     */
    public function addReference(string $name, object $object)
    {
        $class = $this->getRealClass($object::class);
        if (isset($this->referencesByClass[$class][$name])) {
            throw new BadMethodCallException(sprintf(
                'Reference to "%s" for class "%s" already exists, use method setReference() in order to override it',
                $name,
                $class,
            ));
        }

        $this->setReference($name, $object);
    }

    /**
     * Loads an object using stored reference
     * named by $name
     *
     * @psalm-param class-string<T> $class
     *
     * @return object
     * @psalm-return T
     *
     * @throws OutOfBoundsException - if repository does not exist.
     *
     * @template T of object
     */
    public function getReference(string $name, string $class)
    {
        if (! $this->hasReference($name, $class)) {
            throw new OutOfBoundsException(sprintf('Reference to "%s" for class "%s" does not exist', $name, $class));
        }

        $reference = $this->referencesByClass[$class][$name];

        $identity = ($this->identitiesByClass[$class][$name] ?? null);

        $meta = $this->manager->getClassMetadata($class);

        if (! $this->manager->contains($reference) && $identity !== null) {
            $reference                              = $this->manager->getReference($meta->name, $identity);
            $this->referencesByClass[$class][$name] = $reference; // already in identity map
        }

        return $reference;
    }

    /**
     * Check if an object is stored using reference
     * named by $name
     *
     * @psalm-param class-string $class
     *
     * @return bool
     */
    public function hasReference(string $name, string $class)
    {
        return isset($this->referencesByClass[$class][$name]);
    }

    /**
     * Searches for reference names in the
     * list of stored references
     *
     * @return array<string>
     */
    public function getReferenceNames(object $reference)
    {
        $class = $this->getRealClass($reference::class);
        if (! isset($this->referencesByClass[$class])) {
            return [];
        }

        return array_keys($this->referencesByClass[$class], $reference, true);
    }

    /**
     * Checks if reference has identity stored
     *
     * @param class-string $class
     *
     * @return bool
     */
    public function hasIdentity(string $name, string $class)
    {
        return array_key_exists($class, $this->identitiesByClass) && array_key_exists($name, $this->identitiesByClass[$class]);
    }

    /**
     * Get all stored identities
     *
     * @psalm-return array<class-string, array<string, object>>
     */
    public function getIdentitiesByClass(): array
    {
        return $this->identitiesByClass;
    }

    /**
     * Get all stored references
     *
     * @psalm-return array<class-string, array<string, object>>
     */
    public function getReferencesByClass(): array
    {
        return $this->referencesByClass;
    }

    /**
     * Get object manager
     *
     * @return ObjectManager
     */
    public function getManager()
    {
        return $this->manager;
    }

    /**
     * Get real class name of a reference that could be a proxy
     *
     * @param string $className Class name of reference object
     *
     * @return string
     */
    protected function getRealClass(string $className)
    {
        return $this->manager->getClassMetadata($className)->getName();
    }

    /**
     * Checks if object has identifier already in unit of work.
     *
     * @return bool
     */
    private function hasIdentifier(object $reference)
    {
        // in case if reference is set after flush, store its identity
        $uow = $this->manager->getUnitOfWork();

        if ($this->manager instanceof PhpcrDocumentManager) {
            return $uow->contains($reference);
        }

        return $uow->isInIdentityMap($reference);
    }
}
