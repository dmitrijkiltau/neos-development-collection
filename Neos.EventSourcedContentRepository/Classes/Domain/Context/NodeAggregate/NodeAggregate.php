<?php
declare(strict_types=1);

namespace Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate;

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\NodeAggregate\NodeAggregateIdentifier;
use Neos\ContentRepository\Domain\NodeAggregate\NodeName;
use Neos\ContentRepository\Domain\NodeType\NodeTypeName;
use Neos\EventSourcedContentRepository\Domain\Context\Node\Event\NodeWasMoved;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePointSet;
use Neos\EventSourcedContentRepository\Domain\Context\Node\NodeEventPublisher;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Command\CreateNodeAggregateWithNode;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Command\CreateRootNodeAggregateWithNode;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodeAggregateWithNodeWasCreated;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\RootNodeAggregateWithNodeWasCreated;
use Neos\EventSourcedContentRepository\Domain\ValueObject\PropertyValues;
use Neos\EventSourcing\Event\Decorator\EventWithIdentifier;
use Neos\EventSourcing\Event\DomainEventInterface;
use Neos\EventSourcing\Event\DomainEvents;
use Neos\EventSourcing\EventStore\EventStore;
use Neos\EventSourcing\EventStore\EventStream;
use Neos\EventSourcing\EventStore\Exception\EventStreamNotFoundException;
use Neos\EventSourcing\EventStore\StreamName;

/**
 * The node aggregate
 *
 * Aggregates all nodes with a shared external identity that are varied across the Dimension Space.
 * An example would be a product node that is translated into different languages but uses a shared identifier,
 * e.g. MPN or GTIN
 *
 * The aggregate enforces that each dimension space point can only ever be occupied by one of its nodes.
 */
final class NodeAggregate implements ReadableNodeAggregateInterface
{
    /**
     * @var NodeAggregateIdentifier
     */
    private $identifier;

    /**
     * @var StreamName
     */
    private $streamName;

    /**
     * @var EventStore
     */
    private $eventStore;

    /**
     * @var EventStream
     */
    protected $eventStream;

    /**
     * @var NodeEventPublisher
     */
    protected $nodeEventPublisher;

    public function __construct(
        NodeAggregateIdentifier $identifier,
        StreamName $streamName,
        EventStore $eventStore,
        NodeEventPublisher $nodeEventPublisher
    ) {
        $this->identifier = $identifier;
        $this->eventStore = $eventStore;
        $this->streamName = $streamName;
        $this->nodeEventPublisher = $nodeEventPublisher;
    }

    /**
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @throws DimensionSpacePointIsNotYetOccupied
     */
    public function requireDimensionSpacePointToBeOccupied(DimensionSpacePoint $dimensionSpacePoint)
    {
        if (!$this->occupiesDimensionSpacePoint($dimensionSpacePoint)) {
            throw new DimensionSpacePointIsNotYetOccupied('The source dimension space point "' . $dimensionSpacePoint . '" is not yet occupied',
                1521312039);
        }
    }

    /**
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @throws DimensionSpacePointIsAlreadyOccupied
     */
    public function requireDimensionSpacePointToBeUnoccupied(DimensionSpacePoint $dimensionSpacePoint)
    {
        if ($this->occupiesDimensionSpacePoint($dimensionSpacePoint)) {
            throw new DimensionSpacePointIsAlreadyOccupied('The target dimension space point "' . $dimensionSpacePoint . '" is already occupied',
                1521314881);
        }
    }

    /**
     * @param CreateRootNodeAggregateWithNode $command
     * @param DimensionSpacePointSet $visibleDimensionSpacePoints
     * @return DomainEvents
     * @throws NodeAggregateCurrentlyExists
     */
    public function createRootWithNode(
        CreateRootNodeAggregateWithNode $command,
        DimensionSpacePointSet $visibleDimensionSpacePoints
    ): DomainEvents {
        /*
        if ($this->existsCurrently()) {
            throw new NodeAggregateCurrentlyExists('Root node aggregate "' . $this->identifier . '" does currently exist and can thus not be created.', 1541781941);
        }*/

        $events = DomainEvents::withSingleEvent(
            EventWithIdentifier::create(
                new RootNodeAggregateWithNodeWasCreated(
                    $command->getContentStreamIdentifier(),
                    $this->identifier,
                    $command->getNodeTypeName(),
                    $visibleDimensionSpacePoints,
                    NodeAggregateClassification::root(),
                    $command->getInitiatingUserIdentifier()
                )
            )
        );

        $this->nodeEventPublisher->publishMany(
            $this->streamName,
            $events
        );

        return $events;
    }

    /**
     * @param CreateNodeAggregateWithNode $command
     * @param DimensionSpacePointSet $visibleDimensionSpacePoints
     * @param PropertyValues $initialPropertyValues
     * @return DomainEvents
     * @throw NodeAggregateCurrentlyExists
     */
    public function createRegularWithNode(
        CreateNodeAggregateWithNode $command,
        DimensionSpacePointSet $visibleDimensionSpacePoints,
        PropertyValues $initialPropertyValues
    ): DomainEvents {
        /*
        if ($this->existsCurrently()) {
            throw new NodeAggregateCurrentlyExists('Node aggregate "' . $this->identifier . '" does currently exist and can thus not be created.', 1541679244);
        }*/

        $events = DomainEvents::withSingleEvent(
            EventWithIdentifier::create(
                new NodeAggregateWithNodeWasCreated(
                    $command->getContentStreamIdentifier(),
                    $this->identifier,
                    $command->getNodeTypeName(),
                    $command->getOriginDimensionSpacePoint(),
                    $visibleDimensionSpacePoints,
                    $command->getParentNodeAggregateIdentifier(),
                    $command->getNodeName(),
                    $initialPropertyValues,
                    NodeAggregateClassification::regular(),
                    $command->getSucceedingSiblingNodeAggregateIdentifier()
                )
            )
        );

        $this->nodeEventPublisher->publishMany(
            $this->streamName,
            $events
        );

        return $events;
    }

    /**
     * @param CreateNodeAggregateWithNode $command
     * @param NodeTypeName $nodeTypeName
     * @param DimensionSpacePointSet $visibleDimensionSpacePoints
     * @param NodeAggregateIdentifier $parentNodeAggregateIdentifier
     * @param NodeName $nodeName
     * @param PropertyValues $initialPropertyValues
     * @param NodeAggregateIdentifier|null $precedingNodeAggregateIdentifier
     * @return DomainEvents
     * @throw NodeAggregateCurrentlyExists
     */
    public function createTetheredWithNode(
        CreateNodeAggregateWithNode $command,
        NodeTypeName $nodeTypeName,
        DimensionSpacePointSet $visibleDimensionSpacePoints,
        NodeAggregateIdentifier $parentNodeAggregateIdentifier,
        NodeName $nodeName,
        PropertyValues $initialPropertyValues,
        NodeAggregateIdentifier $precedingNodeAggregateIdentifier = null
    ): DomainEvents {
        /*
        if ($this->existsCurrently()) {
            throw new NodeAggregateCurrentlyExists('Node aggregate "' . $this->identifier . '" does currently exist and can thus not be created.', 1541755683);
        }*/

        $events = DomainEvents::withSingleEvent(
            EventWithIdentifier::create(
                new NodeAggregateWithNodeWasCreated(
                    $command->getContentStreamIdentifier(),
                    $this->identifier,
                    $nodeTypeName,
                    $command->getOriginDimensionSpacePoint(),
                    $visibleDimensionSpacePoints,
                    $parentNodeAggregateIdentifier,
                    $nodeName,
                    $initialPropertyValues,
                    NodeAggregateClassification::tethered(),
                    $precedingNodeAggregateIdentifier
                )
            )
        );

        $this->nodeEventPublisher->publishMany(
            $this->streamName,
            $events
        );

        return $events;
    }

    public function existsCurrently(): bool
    {
        /** this currently cannot be evaluated due to event store limitations and thus must be done via soft constraint checks */
        $existsCurrently = false;

        $this->traverseEventStream(function (DomainEventInterface $event) use (&$existsCurrently) {
            switch (get_class($event)) {
                case RootNodeAggregateWithNodeWasCreated::class:
                    $existsCurrently = true;
                    break;
                case NodeAggregateWithNodeWasCreated::class:
                    $existsCurrently = true;
                    break;
                // @todo handle NodeWasDeleted for toggling to false
                default:
                    continue;
            }
        });

        return $existsCurrently;
    }

    public function getOccupiedDimensionSpacePoints(): DimensionSpacePointSet
    {
        $occupiedDimensionSpacePoints = [];

        $eventStream = $this->getEventStream();
        if ($eventStream) {
            foreach ($eventStream as $eventEnvelope) {
                $event = $eventEnvelope->getDomainEvent();
                switch (get_class($event)) {
                    case Event\NodeAggregateWithNodeWasCreated::class:
                        /** @var Event\NodeAggregateWithNodeWasCreated $event */
                        $occupiedDimensionSpacePoints[$event->getOriginDimensionSpacePoint()->getHash()] = $event->getOriginDimensionSpacePoint();
                        break;
                    case Event\NodeSpecializationVariantWasCreated::class:
                        /** @var Event\NodeSpecializationVariantWasCreated $event */
                        $occupiedDimensionSpacePoints[$event->getSpecializationOrigin()->getHash()] = $event->getSpecializationOrigin();
                        break;
                    case Event\NodeGeneralizationVariantWasCreated::class:
                        /** @var Event\NodeGeneralizationVariantWasCreated $event */
                        $occupiedDimensionSpacePoints[$event->getGeneralizationOrigin()->getHash()] = $event->getGeneralizationOrigin();
                        break;
                    default:
                        continue 2;
                }
            }
        }

        return new DimensionSpacePointSet($occupiedDimensionSpacePoints);
    }

    public function getVisibleInDimensionSpacePoints(): DimensionSpacePointSet
    {
        $visibleInDimensionSpacePoints = [];

        $eventStream = $this->getEventStream();
        if ($eventStream) {
            foreach ($eventStream as $eventEnvelope) {
                $event = $eventEnvelope->getDomainEvent();
                switch (get_class($event)) {
                    case RootNodeAggregateWithNodeWasCreated::class:
                    case NodeAggregateWithNodeWasCreated::class:
                        /** @var RootNodeAggregateWithNodeWasCreated|NodeAggregateWithNodeWasCreated $event */
                        foreach ($event->getVisibleInDimensionSpacePoints()->getPoints() as $visibleDimensionSpacePoint) {
                            $visibleInDimensionSpacePoints[$visibleDimensionSpacePoint->getHash()] = $visibleDimensionSpacePoint;
                        }
                        break;
                    case Event\NodeSpecializationVariantWasCreated::class:
                        /** @var Event\NodeSpecializationVariantWasCreated $event */
                        foreach ($event->getSpecializationCoverage()->getPoints() as $visibleDimensionSpacePoint) {
                            $visibleInDimensionSpacePoints[$visibleDimensionSpacePoint->getHash()] = $visibleDimensionSpacePoint;
                        }
                        break;
                    case Event\NodeGeneralizationVariantWasCreated::class:
                        /** @var Event\NodeGeneralizationVariantWasCreated $event */
                        foreach ($event->getGeneralizationCoverage()->getPoints() as $visibleDimensionSpacePoint) {
                            $visibleInDimensionSpacePoints[$visibleDimensionSpacePoint->getHash()] = $visibleDimensionSpacePoint;
                        }
                        break;
                    default:
                        continue 2;
                }
            }
        }

        return new DimensionSpacePointSet($visibleInDimensionSpacePoints);
    }

    public function isVisibleInDimensionSpacePoint(DimensionSpacePoint $dimensionSpacePoint): bool
    {
        return $this->getVisibleInDimensionSpacePoints()->contains($dimensionSpacePoint);
    }

    public function occupiesDimensionSpacePoint(DimensionSpacePoint $dimensionSpacePoint): bool
    {
        $dimensionSpacePointOccupied = false;
        $eventStream = $this->getEventStream();
        if ($eventStream) {
            foreach ($eventStream as $eventEnvelope) {
                $event = $eventEnvelope->getDomainEvent();
                switch (get_class($event)) {
                    case NodeAggregateWithNodeWasCreated::class:
                        /** @var NodeAggregateWithNodeWasCreated $event */
                        $dimensionSpacePointOccupied = $dimensionSpacePointOccupied || $event->getOriginDimensionSpacePoint()->equals($dimensionSpacePoint);
                        break;
                    case Event\NodeSpecializationVariantWasCreated::class:
                        /** @var Event\NodeSpecializationVariantWasCreated $event */
                        $dimensionSpacePointOccupied = $dimensionSpacePointOccupied || $event->getSpecializationOrigin()->equals($dimensionSpacePoint);
                        break;
                    case Event\NodeGeneralizationVariantWasCreated::class:
                        /** @var Event\NodeGeneralizationVariantWasCreated $event */
                        $dimensionSpacePointOccupied = $dimensionSpacePointOccupied || $event->getGeneralizationOrigin()->equals($dimensionSpacePoint);
                        break;
                    default:
                        continue 2;
                }
            }
        }

        return $dimensionSpacePointOccupied;
    }

    /**
     * @return array|NodeAggregateIdentifier[]
     */
    public function getParentIdentifiers(): array
    {
        $parentIdentifiers = [];
        $this->traverseEventStream(function (DomainEventInterface $event) use (&$parentIdentifiers) {
            switch (get_class($event)) {
                case NodeAggregateWithNodeWasCreated::class:
                    /** @var NodeAggregateWithNodeWasCreated $event */
                    foreach ($event->getVisibleInDimensionSpacePoints() as $dimensionSpacePoint) {
                        $parentIdentifiers[(string)$dimensionSpacePoint] = $event->getParentNodeAggregateIdentifier();
                    }
                    break;
                case NodeWasMoved::class:
                    // @todo implement me
                default:
                    continue;
            }
        });

        return $parentIdentifiers;
    }

    public function getNodeTypeName(): NodeTypeName
    {
        $nodeTypeName = null;
        $this->traverseEventStream(function (DomainEventInterface $event) use (&$nodeTypeName) {
            switch (get_class($event)) {
                case RootNodeAggregateWithNodeWasCreated::class:
                    /** @var RootNodeAggregateWithNodeWasCreated $event */
                    $nodeTypeName = $event->getNodeTypeName();
                    break;
                case NodeAggregateWithNodeWasCreated::class:
                    /** @var NodeAggregateWithNodeWasCreated $event */
                    $nodeTypeName = $event->getNodeTypeName();
                    break;
                // @todo handle NodeAggregateTypeWasChanged
                // @todo handle NodeWasDeleted for nulling
                default:
                    continue;
            }
        });

        return $nodeTypeName;
    }

    public function getNodeName(): ?NodeName
    {
        $nodeName = null;
        $this->traverseEventStream(function (DomainEventInterface $event) use (&$nodeName) {
            switch (get_class($event)) {
                case NodeAggregateWithNodeWasCreated::class:
                    /** @var NodeAggregateWithNodeWasCreated $event */
                    $nodeName = $event->getNodeName();
                    break;
                // @todo handle NodeAggregateNameWasChanged
                // @todo handle NodeWasDeleted for nulling
                default:
                    continue;
            }
        });

        return $nodeName;
    }

    public function getIdentifier(): NodeAggregateIdentifier
    {
        return $this->identifier;
    }

    public function getStreamName(): StreamName
    {
        return $this->streamName;
    }

    private function traverseEventStream(callable $callback): void
    {
        $eventStream = $this->getEventStream();
        if (!is_null($eventStream)) {
            foreach ($this->getEventStream() as $eventEnvelope) {
                $event = $eventEnvelope->getDomainEvent();
                $callback($event);
            }
        }
    }

    private function getEventStream(): ?EventStream
    {
        try {
            return $this->eventStore->load($this->streamName);
        } catch (EventStreamNotFoundException $eventStreamNotFound) {
            return null;
        }
    }

    /**
     * A node aggregate covers a dimension space point if any node is visible in it
     * in that is has an incoming edge in it.
     *
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @return bool
     */
    public function coversDimensionSpacePoint(DimensionSpacePoint $dimensionSpacePoint): bool
    {
        return $this->getVisibleInDimensionSpacePoints()->contains($dimensionSpacePoint);
    }

    public function getCoveredDimensionSpacePoints(): DimensionSpacePointSet
    {
        return $this->getVisibleInDimensionSpacePoints();
    }

    public function getClassification(): NodeAggregateClassification
    {
        throw new \RuntimeException('getClassification is not yet supported by the write side node aggregate');
    }

    public function isRoot(): bool
    {
        throw new \RuntimeException('isRoot is not yet supported by the write side node aggregate');
    }

    public function isTethered(): bool
    {
        throw new \RuntimeException('isTethered is not yet supported by the write side node aggregate');
    }
}
