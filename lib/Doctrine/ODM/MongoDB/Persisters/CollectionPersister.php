<?php

namespace Doctrine\ODM\MongoDB\Persisters;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\LockException;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadataInfo;
use Doctrine\ODM\MongoDB\PersistentCollection\PersistentCollectionInterface;
use Doctrine\ODM\MongoDB\UnitOfWork;
use Doctrine\ODM\MongoDB\Utility\CollectionHelper;

/**
 * The CollectionPersister is responsible for persisting collections of embedded
 * or referenced documents. When a PersistentCollection is scheduledForDeletion
 * in the UnitOfWork by calling PersistentCollection::clear() or is
 * de-referenced in the domain application code, CollectionPersister::delete()
 * will be called. When documents within the PersistentCollection are added or
 * removed, CollectionPersister::update() will be called, which may set the
 * entire collection or delete/insert individual elements, depending on the
 * mapping strategy.
 *
 * @since       1.0
 */
class CollectionPersister
{
    /**
     * The DocumentManager instance.
     *
     * @var DocumentManager
     */
    private $dm;

    /**
     * The PersistenceBuilder instance.
     *
     * @var PersistenceBuilder
     */
    private $pb;

    /**
     * Constructs a new CollectionPersister instance.
     *
     * @param DocumentManager $dm
     * @param PersistenceBuilder $pb
     * @param UnitOfWork $uow
     */
    public function __construct(DocumentManager $dm, PersistenceBuilder $pb, UnitOfWork $uow)
    {
        $this->dm = $dm;
        $this->pb = $pb;
        $this->uow = $uow;
    }

    /**
     * Deletes a PersistentCollection instance completely from a document using $unset.
     *
     * @param PersistentCollectionInterface $coll
     * @param array $options
     */
    public function delete(PersistentCollectionInterface $coll, array $options)
    {
        $mapping = $coll->getMapping();
        if ($mapping['isInverseSide']) {
            return; // ignore inverse side
        }
        if (CollectionHelper::isAtomic($mapping['strategy'])) {
            throw new \UnexpectedValueException($mapping['strategy'] . ' delete collection strategy should have been handled by DocumentPersister. Please report a bug in issue tracker');
        }
        list($propertyPath, $parent) = $this->getPathAndParent($coll);
        $query = array('$unset' => array($propertyPath => true));
        $this->executeQuery($parent, $query, $options);
    }

    /**
     * Updates a PersistentCollection instance deleting removed rows and
     * inserting new rows.
     *
     * @param PersistentCollectionInterface $coll
     * @param array $options
     */
    public function update(PersistentCollectionInterface $coll, array $options)
    {
        $mapping = $coll->getMapping();

        if ($mapping['isInverseSide']) {
            return; // ignore inverse side
        }

        switch ($mapping['strategy']) {
            case ClassMetadataInfo::STORAGE_STRATEGY_ATOMIC_SET:
            case ClassMetadataInfo::STORAGE_STRATEGY_ATOMIC_SET_ARRAY:
                throw new \UnexpectedValueException($mapping['strategy'] . ' update collection strategy should have been handled by DocumentPersister. Please report a bug in issue tracker');

            case ClassMetadataInfo::STORAGE_STRATEGY_SET:
            case ClassMetadataInfo::STORAGE_STRATEGY_SET_ARRAY:
                $this->setCollection($coll, $options);
                break;

            case ClassMetadataInfo::STORAGE_STRATEGY_ADD_TO_SET:
            case ClassMetadataInfo::STORAGE_STRATEGY_PUSH_ALL:
                $coll->initialize();
                $this->deleteElements($coll, $options);
                $this->insertElements($coll, $options);
                break;

            default:
                throw new \UnexpectedValueException('Unsupported collection strategy: ' . $mapping['strategy']);
        }
    }

    /**
     * Sets a PersistentCollection instance.
     *
     * This method is intended to be used with the "set" or "setArray"
     * strategies. The "setArray" strategy will ensure that the collection is
     * set as a BSON array, which means the collection elements will be
     * reindexed numerically before storage.
     *
     * @param PersistentCollectionInterface $coll
     * @param array $options
     */
    private function setCollection(PersistentCollectionInterface $coll, array $options)
    {
        list($propertyPath, $parent) = $this->getPathAndParent($coll);
        $coll->initialize();
        $mapping = $coll->getMapping();
        $setData = $this->pb->prepareAssociatedCollectionValue($coll, CollectionHelper::usesSet($mapping['strategy']));
        $query = array('$set' => array($propertyPath => $setData));
        $this->executeQuery($parent, $query, $options);
    }

    /**
     * Deletes removed elements from a PersistentCollection instance.
     *
     * This method is intended to be used with the "pushAll" and "addToSet"
     * strategies.
     *
     * @param PersistentCollectionInterface $coll
     * @param array $options
     */
    private function deleteElements(PersistentCollectionInterface $coll, array $options)
    {
        $deleteDiff = $coll->getDeleteDiff();

        if (empty($deleteDiff)) {
            return;
        }

        list($propertyPath, $parent) = $this->getPathAndParent($coll);

        $query = array('$unset' => array());

        foreach ($deleteDiff as $key => $document) {
            $query['$unset'][$propertyPath . '.' . $key] = true;
        }

        $this->executeQuery($parent, $query, $options);

        /**
         * @todo This is a hack right now because we don't have a proper way to
         * remove an element from an array by its key. Unsetting the key results
         * in the element being left in the array as null so we have to pull
         * null values.
         */
        $this->executeQuery($parent, array('$pull' => array($propertyPath => null)), $options);
    }

    /**
     * Inserts new elements for a PersistentCollection instance.
     *
     * This method is intended to be used with the "pushAll" and "addToSet"
     * strategies.
     *
     * @param PersistentCollectionInterface $coll
     * @param array $options
     */
    private function insertElements(PersistentCollectionInterface $coll, array $options)
    {
        $insertDiff = $coll->getInsertDiff();

        if (empty($insertDiff)) {
            return;
        }

        $mapping = $coll->getMapping();

        switch ($mapping['strategy']) {
            case ClassMetadataInfo::STORAGE_STRATEGY_PUSH_ALL:
                $operator = 'push';
                break;

            case ClassMetadataInfo::STORAGE_STRATEGY_ADD_TO_SET:
                $operator = 'addToSet';
                break;

            default:
                throw new \LogicException("Invalid strategy {$mapping['strategy']} given for insertElements");
        }

        list($propertyPath, $parent) = $this->getPathAndParent($coll);

        $callback = isset($mapping['embedded'])
            ? function($v) use ($mapping) { return $this->pb->prepareEmbeddedDocumentValue($mapping, $v); }
            : function($v) use ($mapping) { return $this->pb->prepareReferencedDocumentValue($mapping, $v); };

        $value = array_values(array_map($callback, $insertDiff));

        $query = ['$' . $operator => [$propertyPath => ['$each' => $value]]];

        $this->executeQuery($parent, $query, $options);
    }

    /**
     * Gets the parent information for a given PersistentCollection. It will
     * retrieve the top-level persistent Document that the PersistentCollection
     * lives in. We can use this to issue queries when updating a
     * PersistentCollection that is multiple levels deep inside an embedded
     * document.
     *
     *     <code>
     *     list($path, $parent) = $this->getPathAndParent($coll)
     *     </code>
     *
     * @param PersistentCollectionInterface $coll
     * @return array $pathAndParent
     */
    private function getPathAndParent(PersistentCollectionInterface $coll)
    {
        $mapping = $coll->getMapping();
        $fields = array();
        $parent = $coll->getOwner();
        while (null !== ($association = $this->uow->getParentAssociation($parent))) {
            list($m, $owner, $field) = $association;
            if (isset($m['reference'])) {
                break;
            }
            $parent = $owner;
            $fields[] = $field;
        }
        $propertyPath = implode('.', array_reverse($fields));
        $path = $mapping['name'];
        if ($propertyPath) {
            $path = $propertyPath . '.' . $path;
        }
        return array($path, $parent);
    }

    /**
     * Executes a query updating the given document.
     *
     * @param object $document
     * @param array $newObj
     * @param array $options
     */
    private function executeQuery($document, array $newObj, array $options)
    {
        $className = get_class($document);
        $class = $this->dm->getClassMetadata($className);
        $id = $class->getDatabaseIdentifierValue($this->uow->getDocumentIdentifier($document));
        $query = array('_id' => $id);
        if ($class->isVersioned) {
            $query[$class->fieldMappings[$class->versionField]['name']] = $class->reflFields[$class->versionField]->getValue($document);
        }
        $collection = $this->dm->getDocumentCollection($className);
        $result = $collection->updateOne($query, $newObj, $options);
        if ($class->isVersioned && ! $result->getMatchedCount()) {
            throw LockException::lockFailed($document);
        }
    }
}
