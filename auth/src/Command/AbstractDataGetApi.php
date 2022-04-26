<?php

namespace App\Command;

use App\Entity\ApiToken;
use App\Entity\WbDataEntity\WbData;
use App\Entity\WbDataEntity\WbDataProperty;
use App\Helper\Status\ApiTokenStatus;
use App\Service\WbApiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpFoundation\Response;

abstract class AbstractDataGetApi extends Command
{
    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected WbApiService $service
    ){
        parent::__construct();
    }

    protected function insertData(ApiToken $token)
    {
        if($token->getStatus() != ApiTokenStatus::ACTIVE){
            return;
        }
        $this->service->setToken($token->getToken());

        try{
            $sales = $this->service->sales();
        }catch (\Exception $ex){
            switch ($ex->getCode()){
                case Response::HTTP_BAD_REQUEST:
                    $token->setStatus(ApiTokenStatus::BLOCK);
                    $this->entityManager->flush();
                    return;
                case Response::HTTP_TOO_MANY_REQUESTS:
                    sleep(90);
                    $sales = $this->service->sales();
            }
        }
        $incomes = $this->service->incomes();
        $orders = $this->service->orders();
        $stocks = $this->service->stocks();
        $reports = $this->service->reportDetailByPeriod();

        $wbData = $this
            ->entityManager
            ->getRepository(WbData::class)
            ->findOneBy(['apiToken' => $token->getId()])
        ;

        if($wbData){
            $this
                ->entityManager
                ->getRepository(WbDataProperty::class)
                ->removeAllProp($wbData->getId());

            $wbData->setDate(new \DateTime());
        }else{
            $wbData = (new WbData())
                ->setApiToken($token);
            $this->entityManager->persist($wbData);
        }

        // todo пока удалить метод был удален из wb
        //$excise = $this->service->exciseGoods();

        foreach ($sales as $sale){
            $wbData->addWbDataSale(
                (new WbDataProperty())
                    ->setProperty(json_encode($sale))
                    ->setWbDataSale($wbData)
            );
        }
        foreach ($incomes as $income){
            $wbData->addWbDataIncome(
                (new WbDataProperty())
                    ->setProperty(json_encode($income))
                    ->setWbDataIncome($wbData)
            );
        }
        foreach ($orders as $order){
            $wbData->addWbDataOrder(
                (new WbDataProperty())
                    ->setProperty(json_encode($order))
                    ->setWbDataOrder($wbData)
            );
        }
        foreach ($stocks as $stock){
            $wbData->addWbDataStock(
                (new WbDataProperty())
                    ->setProperty(json_encode($stock))
                    ->setWbDataStock($wbData)
            );
        }
        foreach ($reports as $report){
            $wbData->addWbDataReport(
                (new WbDataProperty())
                    ->setProperty(json_encode($report))
                    ->setWbDataReport($wbData)
            );
        }
        $this->entityManager->flush();
    }
    public function deleteOldWbData()
    {
        $wbDatas = $this->entityManager->getRepository(WbData::class)->findAll();
        foreach ($wbDatas as $wbData){
            if($wbData->getDate()->modify("+1 day") < new \DateTime()){
                $this
                    ->entityManager
                    ->getRepository(WbDataProperty::class)
                    ->removeAllProp($wbData->getId())
                ;
            }
        }
    }
}