<?php
/*
 *  Copyright 2026.  Baks.dev <admin@baks.dev>
 *  
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *  
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *  
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

namespace BaksDev\Orders\Order\Entity\Event;

use BaksDev\Core\Entity\EntityEvent;
use BaksDev\Orders\Order\Entity\Event\Posting\OrderPosting;
use BaksDev\Orders\Order\Entity\Event\Project\OrderProject;
use BaksDev\Orders\Order\Entity\Invariable\OrderInvariable;
use BaksDev\Orders\Order\Entity\Modify\OrderModify;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Entity\Print\OrderPrint;
use BaksDev\Orders\Order\Entity\Products\OrderProduct;
use BaksDev\Orders\Order\Entity\Services\OrderService;
use BaksDev\Orders\Order\Entity\User\Delivery\OrderDelivery;
use BaksDev\Orders\Order\Entity\User\OrderUser;
use BaksDev\Orders\Order\Type\Event\OrderEventUid;
use BaksDev\Orders\Order\Type\Id\OrderUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use BaksDev\Services\Entity\Service;
use BaksDev\Users\Profile\UserProfile\Type\Event\UserProfileEventUid;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\User\Type\Id\UserUid;
use DateInterval;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

// Event

#[ORM\Entity]
#[ORM\Table(name: 'orders_event')]
#[ORM\Index(columns: ['status'])]
#[ORM\Index(columns: ['created'])]
#[ORM\Index(columns: ['profile'])]
#[ORM\Index(columns: ['danger'])]
class OrderEvent extends EntityEvent
{
    /** ID */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[ORM\Id]
    #[ORM\Column(type: OrderEventUid::TYPE)]
    private OrderEventUid $id;

    /** ID заказа */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[ORM\Column(type: OrderUid::TYPE)]
    private ?OrderUid $orders = null;

    /** Товары в заказе */
    #[Assert\When(expression: 'this.isServiceEmpty() === true', constraints: new Assert\Count(min: 1))]
    #[ORM\OneToMany(targetEntity: OrderProduct::class, mappedBy: 'event', cascade: ['all'])]
    private Collection $product;

    #[Assert\When(expression: 'this.isProductEmpty() === true', constraints: new Assert\Count(min: 1))]
    #[ORM\OneToMany(targetEntity: OrderService::class, mappedBy: 'event', cascade: ['all'])]
    private Collection $serv;

    /** Постоянная величина Invariable */
    #[ORM\OneToOne(targetEntity: OrderInvariable::class, mappedBy: 'event', cascade: ['all'])]
    private ?OrderInvariable $invariable = null;

    /** Информация о разделенном заказе - EntityReadonly */
    #[ORM\OneToOne(targetEntity: OrderPosting::class, mappedBy: 'event', cascade: ['all'])]
    private ?OrderPosting $posting = null;


    /** Статус заказа */
    #[Assert\NotBlank]
    #[ORM\Column(type: OrderStatus::TYPE)]
    private OrderStatus $status;

    /**
     * Ответственный
     *
     * @deprecated переносится в invariable
     */
    #[ORM\Column(type: UserProfileUid::TYPE, nullable: true)]
    private ?UserProfileUid $profile = null;


    /**
     * Дата заказа
     *
     * @deprecated переносится в invariable
     */
    #[Assert\NotBlank]
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $created;


    /** Идентификатор проекта */
    #[ORM\OneToOne(targetEntity: OrderProject::class, mappedBy: 'event', cascade: ['all'])]
    private ?OrderProject $project = null;

    /** Модификатор */
    #[ORM\OneToOne(targetEntity: OrderModify::class, mappedBy: 'event', cascade: ['all'])]
    private OrderModify $modify;

    /** Пользователь (Клиент) */
    #[ORM\OneToOne(targetEntity: OrderUser::class, mappedBy: 'event', cascade: ['all'])]
    private ?OrderUser $usr = null;

    /** Флаг о печати */
    #[ORM\OneToOne(targetEntity: OrderPrint::class, mappedBy: 'event', cascade: ['all'])]
    private ?OrderPrint $printed = null;

    /** Комментарий к заказу */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /** Выделить заказ */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $danger = false;


    public function __construct()
    {
        $this->id = new OrderEventUid();
        $this->modify = new OrderModify($this);
        $this->created = new DateTimeImmutable()->add(DateInterval::createFromDateString('1 minute'));
        $this->status = new OrderStatus(OrderStatusNew::class);
        $this->serv = new ArrayCollection();
        $this->product = new ArrayCollection();
    }

    public function __clone()
    {
        $this->id = clone $this->id;
    }

    public function __toString(): string
    {
        return (string) $this->id;
    }

    public function getMain(): ?OrderUid
    {
        return $this->orders;
    }

    public function isPrinted(): bool
    {
        return $this->printed?->isPrinted() === true;
    }

    public function setMain(OrderUid|Order $order): void
    {
        $this->orders = $order instanceof Order ? $order->getId() : $order;
    }

    public function getId(): OrderEventUid
    {
        return $this->id;
    }

    public function isInvariable(): bool
    {
        return $this->invariable instanceof OrderInvariable;
    }

    public function setInvariable(OrderInvariable|false $invariable): self
    {
        if($invariable instanceof OrderInvariable)
        {
            $this->invariable = $invariable;
        }

        return $this;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function isStatusEquals(mixed $status): bool
    {
        return $this->status->equals($status);
    }

    public function isDeliveryTypeEquals(mixed $delivery): bool
    {
        if(false === ($this->usr instanceof OrderUser))
        {
            return false;
        }

        return $this->usr->getDelivery()->getDeliveryType()->equals($delivery);
    }

    public function isPaymentTypeEquals(mixed $payment): bool
    {
        if(false === ($this->usr instanceof OrderUser))
        {
            return false;
        }

        return $this->usr->getPayment()->getPayment()->equals($payment);
    }

    /**
     * Users.
     */
    public function getDelivery(): ?OrderDelivery
    {
        return $this->usr?->getDelivery();
    }


    public function getOrderNumber(): ?string
    {
        return $this->invariable?->getNumber();
    }

    public function getPostingNumber(): ?string
    {
        return $this->posting?->getValue();
    }

    public function getOrderTokenIdentifier(): ?Uuid
    {
        return $this->invariable?->getToken();
    }

    public function getOrderUser(): ?UserUid
    {
        return $this->invariable?->getUsr();
    }

    public function getOrderProfile(): ?UserProfileUid
    {
        return $this->invariable?->getProfile();
    }

    public function isDanger(): bool
    {
        return $this->danger === true;
    }

    public function getDto($dto): mixed
    {
        $dto = is_string($dto) && class_exists($dto) ? new $dto() : $dto;

        if($dto instanceof OrderEventInterface)
        {
            return parent::getDto($dto);
        }

        throw new InvalidArgumentException(sprintf('Class %s interface error', $dto::class));
    }

    public function setEntity($dto): mixed
    {
        if($dto instanceof OrderEventInterface || $dto instanceof self)
        {
            return parent::setEntity($dto);
        }

        throw new InvalidArgumentException(sprintf('Class %s interface error', $dto::class));
    }

    /** @return Collection<OrderProduct> */
    public function getProduct(): Collection
    {
        return $this->product;
    }

    public function isProductEmpty(): bool
    {
        return $this->product->isEmpty();
    }

    /** Идентификатор события профиля клиента */
    public function getClientProfile(): UserProfileEventUid|false
    {
        if(false === ($this->usr instanceof OrderUser))
        {
            return false;
        }

        return $this->usr->getClientProfile();
    }

    /** @return Collection<int, OrderService> */
    public function getServ(): Collection
    {
        return $this->serv;
    }

    public function isServiceEmpty(): bool
    {
        return $this->serv->isEmpty() === true;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getModifyUser(): ?UserUid
    {
        return $this->modify->getUsr();
    }
}
