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

declare(strict_types=1);

namespace BaksDev\Orders\Order\Messenger\MultiplyOrdersPackage;

use BaksDev\Centrifugo\Server\Publish\CentrifugoPublishInterface;
use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Repository\ExistOrderEventByStatus\ExistOrderEventByStatusInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusPackage;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusUnpaid;
use BaksDev\Products\Product\Repository\CurrentProductIdentifier\CurrentProductIdentifierByEventInterface;
use BaksDev\Products\Product\Repository\CurrentProductIdentifier\CurrentProductIdentifierResult;
use BaksDev\Products\Stocks\BaksDevProductsStocksBundle;
use BaksDev\Products\Stocks\Messenger\Orders\MultiplyProductStocksPackage\MultiplyProductStocksPackageMessage;
use BaksDev\Products\Stocks\Repository\ProductStocksTotalAccess\ProductStocksTotalAccessInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Метод создает складскую заявку (при наличии модуля products-stocks) и меняет статус заказа на Package «Упаковка
 * заказов»
 */
#[Autoconfigure(shared: false)]
#[AsMessageHandler(priority: 100)]
final readonly class MultiplyOrdersPackageDispatcher
{
    public function __construct(
        #[Target('ordersOrderLogger')] private LoggerInterface $logger,
        private CurrentOrderEventInterface $CurrentOrderEventRepository,
        private DeduplicatorInterface $deduplicator,
        private ExistOrderEventByStatusInterface $ExistOrderEventByStatusRepository,
        private CentrifugoPublishInterface $publish,
        private MessageDispatchInterface $messageDispatch,
        private CurrentProductIdentifierByEventInterface $CurrentProductIdentifierByEventRepository,
        private ProductStocksTotalAccessInterface $ProductStocksTotalAccessRepository,
    ) {}

    public function __invoke(MultiplyOrdersPackageMessage $message): void
    {
        $Deduplicator = $this->deduplicator
            ->namespace('orders-order')
            ->deduplication([
                (string) $message->getOrderId(),
                self::class,
            ]);

        if($Deduplicator->isExecuted())
        {
            return;
        }

        $OrderEvent = $this->CurrentOrderEventRepository
            ->forOrder($message->getOrderId())
            ->find();

        if(false === ($OrderEvent instanceof OrderEvent))
        {
            $this->logger->critical(
                sprintf(
                    'orders-order: Ошибка при получении информации о заказе %s при упаковке',
                    $message->getOrderId(),
                ),
                [self::class],
            );

            return;
        }

        /**
         * Если статус заказа Unpaid «В ожидании оплаты» - ждем возврата в NEW «Новый»
         *
         * @note: данная ситуация может возникнуть с Yandex заказами, которые в первую очередь
         * создают заказа со статусом NEW «Новый» для создания резерва в карточке
         */
        if(
            true === $OrderEvent->isStatusEquals(OrderStatusUnpaid::class)
            || true === $OrderEvent->isStatusEquals(OrderStatusCanceled::class)
        )
        {
            return;
        }

        /** Делаем проверку, что статус применяется впервые */
        $isOtherExists = $this->ExistOrderEventByStatusRepository
            ->forOrder($OrderEvent->getMain())
            ->excludeOrderEvent($OrderEvent->getId())
            ->forStatus(OrderStatusPackage::class)
            ->isOtherExists();

        if(true === $isOtherExists)
        {
            $Deduplicator->save();
            return;
        }


        /** Скрываем заказ у всех пользователей */
        $this->publish
            ->addData(['order' => (string) $message->getOrderId()])
            ->send('orders');


        /**
         * Если подключен модуль складского учета - создаем складскую заявку
         */
        if(class_exists(BaksDevProductsStocksBundle::class))
        {
            /** Проверяем остаток */
            foreach($OrderEvent->getProduct() as $OrderProduct)
            {
                $CurrentProductIdentifierResult = $this->CurrentProductIdentifierByEventRepository
                    ->forEvent($OrderProduct->getProduct())
                    ->forOffer($OrderProduct->getOffer())
                    ->forVariation($OrderProduct->getVariation())
                    ->forModification($OrderProduct->getModification())
                    ->find();

                if(false === ($CurrentProductIdentifierResult instanceof CurrentProductIdentifierResult))
                {
                    $this->logger->critical(
                        sprintf('Не было найдено событие продукта %s', $OrderProduct->getProduct()),
                        [self::class],
                    );

                    return;
                }

                $total = $this->ProductStocksTotalAccessRepository
                    ->forProfile($message->getOrderProfile())
                    ->forProduct($CurrentProductIdentifierResult->getProduct())
                    ->forOfferConst($CurrentProductIdentifierResult->getOfferConst())
                    ->forVariationConst($CurrentProductIdentifierResult->getVariationConst())
                    ->forModificationConst($CurrentProductIdentifierResult->getModificationConst())
                    ->get();

                if($total < $OrderProduct->getTotal())
                {
                    $this->logger->warning(
                        sprintf(
                            'Недостаточно продукции %s на складе для события заказа %s',
                            $CurrentProductIdentifierResult->getProduct(),
                            $OrderEvent->getId(),
                        ),
                        [self::class],
                    );

                    return;
                }
            }


            /** Создаем складскую заявку */
            $MultiplyProductStocksPackageMessage = new MultiplyProductStocksPackageMessage(
                $message->getOrderId(),
                $message->getOrderProfile(),
                $message->getCurrentUser(),
            );

            $this->messageDispatch->dispatch(message: $MultiplyProductStocksPackageMessage, transport: 'orders-order');

            $Deduplicator->save();
            return;
        }


        /** Бросаем сообщение на обновление статуса (если не было необходимости создавать складскую заявку) */
        $OrdersPackageByMultiplyMessage = new OrdersPackageByMultiplyMessage(
            $OrderEvent->getId(),
            $message->getCurrentUser(),
            $message->getOrderProfile(),
            $OrderEvent->getComment(),
        );

        $this->messageDispatch->dispatch(message: $OrdersPackageByMultiplyMessage, transport: 'orders-order');

        $Deduplicator->save();
    }
}
