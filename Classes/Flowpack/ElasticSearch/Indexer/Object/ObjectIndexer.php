<?php
namespace Flowpack\ElasticSearch\Indexer\Object;

/*
 * This file is part of the Flowpack.ElasticSearch package.
 *
 * (c) Contributors of the Flowpack Team - flowpack.org
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\ORM\Mapping as ORM;
use Flowpack\ElasticSearch\Domain\Model\Client;
use Flowpack\ElasticSearch\Domain\Model\Document;
use Flowpack\ElasticSearch\Domain\Model\GenericType;
use TYPO3\Flow\Annotations as Flow;

/**
 * This serves functionality for indexing objects
 * Mainly the real time indexing feature will use this (signals being sent and calling this method's properties).
 *
 * @Flow\Scope("singleton")
 */
class ObjectIndexer
{
    /**
     * Defined action names
     */
    const ACTION_TYPE_CREATE = 'create';
    const ACTION_TYPE_UPDATE = 'update';
    const ACTION_TYPE_DELETE = 'delete';

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Persistence\PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Reflection\ReflectionService
     */
    protected $reflectionService;

    /**
     * @Flow\Inject
     * @var \Flowpack\ElasticSearch\Indexer\Object\IndexInformer
     */
    protected $indexInformer;

    /**
     * @Flow\Inject
     * @var \Flowpack\ElasticSearch\Indexer\Object\Transform\TransformerFactory
     */
    protected $transformerFactory;

    /**
     * The client will be injected via Object settings, but however, each member method is able to expect a specific client.
     *
     * @var \Flowpack\ElasticSearch\Domain\Model\Client
     */
    protected $client;

    /**
     * (Re-) indexes an object to the ElasticSearch index, no matter if the change is actually required.
     *
     * @param object $object
     * @param string $signalInformation Signal information, if called from a signal
     * @param \Flowpack\ElasticSearch\Domain\Model\Client $client
     *
     * @return void
     */
    public function indexObject($object, $signalInformation = null, Client $client = null)
    {
        $type = $this->getIndexTypeForObject($object, $client);
        if ($type === null) {
            return null;
        }
        $data = $this->getIndexablePropertiesAndValuesFromObject($object);

        $id = $this->persistenceManager->getIdentifierByObject($object);
        $document = new Document($type, $data, $id);
        $document->store();
    }

    /**
     * Returns a multidimensional array with the indexable, probably transformed values of an object
     *
     * @param $object
     *
     * @return array
     */
    protected function getIndexablePropertiesAndValuesFromObject($object)
    {
        $className = $this->reflectionService->getClassNameByObject($object);
        $data = array();
        foreach ($this->indexInformer->getClassProperties($className) as $propertyName) {
            $value = \TYPO3\Flow\Reflection\ObjectAccess::getProperty($object, $propertyName);
            if (($transformAnnotation = $this->reflectionService->getPropertyAnnotation($className, $propertyName, 'Flowpack\ElasticSearch\Annotations\Transform')) !== null) {
                $value = $this->transformerFactory->create($transformAnnotation->type)->transformByAnnotation($value, $transformAnnotation);
            }
            $data[$propertyName] = $value;
        }

        return $data;
    }

    /**
     * @param $object
     * @param string $signalInformation Signal information, if called from a signal
     * @param \Flowpack\ElasticSearch\Domain\Model\Client $client
     */
    public function removeObject($object, $signalInformation = null, Client $client = null)
    {
        $type = $this->getIndexTypeForObject($object, $client);
        if ($type === null) {
            return;
        }
        $id = $this->persistenceManager->getIdentifierByObject($object);
        $type->deleteDocumentById($id);
    }

    /**
     * Returns if, and what, treatment an object requires regarding the index state,
     * i.e. it checks the given object against the index and tells whether deletion, update or creation is required.
     *
     * @param $object
     * @param \Flowpack\ElasticSearch\Domain\Model\Client $client
     *
     * @return string one of this' ACTION_TYPE_* constants or NULL if no action is required
     */
    public function objectIndexActionRequired($object, Client $client = null)
    {
        $type = $this->getIndexTypeForObject($object, $client);
        if ($type === null) {
            return null;
        }
        $id = $this->persistenceManager->getIdentifierByObject($object);
        $document = $type->findDocumentById($id);
        if ($document !== null) {
            $objectData = $this->getIndexablePropertiesAndValuesFromObject($object);
            if (strcmp(json_encode($objectData), json_encode($document->getData())) === 0) {
                $actionType = null;
            } else {
                $actionType = self::ACTION_TYPE_UPDATE;
            }
        } else {
            $actionType = self::ACTION_TYPE_CREATE;
        }

        return $actionType;
    }

    /**
     * Returns the ElasticSearch type for a specific object, by its annotation
     *
     * @param $object
     * @param \Flowpack\ElasticSearch\Domain\Model\Client $client
     *
     * @return \Flowpack\ElasticSearch\Domain\Model\GenericType
     */
    protected function getIndexTypeForObject($object, Client $client = null)
    {
        if ($client === null) {
            $client = $this->client;
        }
        $className = $this->reflectionService->getClassNameByObject($object);
        $indexAnnotation = $this->indexInformer->getClassAnnotation($className);
        if ($indexAnnotation === null) {
            return null;
        }
        $index = $client->findIndex($indexAnnotation->indexName);
        $type = new GenericType($index, $indexAnnotation->typeName);

        return $type;
    }

    /**
     * Returns the currently used client, used for functional testing
     *
     * @return \Flowpack\ElasticSearch\Domain\Model\Client
     */
    public function getClient()
    {
        return $this->client;
    }
}
