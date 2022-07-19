<?php

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\WbDataEntity\WbDataProperty;
use App\Helper\Status\ApiTokenStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;

class CabinetWbService extends AbstractService
{
    public function compare($query)
    {
        if (key_exists("d1", $query) && key_exists("d2", $query)){
            $d1 = explode(' to ', $query['d1']);
            $d2 = explode(' to ', $query['d2']);
            $context = [
                'd11' => $d1[0],
                'd12' => $d1[1],
                'd21' => $d2[0],
                'd22' => $d2[1],
                'date' => true
            ];
        }else{
            $date = (new \DateTime())->modify("-3 day");
            $context = [
                'd11' => $date->format("d.m.Y"),
                'd12' => $date->modify("+1 day")->format("d.m.Y"),
                'd21' => $date->modify("+1 day")->format("d.m.Y"),
                'd22' => $date->modify("+1 day")->format("d.m.Y")
            ];
        }
        return $context;
    }

    public function getWeeklyReports($id, $query)
    {
        $dataWb = $this->checkStatusToken($id, $query);
        $context['token'] = $dataWb['token'];

        if (!$dataWb['wbData']) {
            $context["processing"] = true;
            return $context;
        }
        $repos = $this->entityManager->getRepository(WbDataProperty::class);
        $arrayPropNames = ["wbDataReport"];
        $arrayNames = ["reports"];
        $data = [];
        for ($i = 0; $i < count($arrayPropNames); $i++) {
            $data[$arrayNames[$i]] = $repos->getProperty($arrayPropNames[$i], $dataWb['wbData']->getId());
        }
        $data["report"] = $data["reports"];
        $data["reports"] = [];
        foreach ($data["report"] as $order) {
            $array = json_decode($order["property"], true);
            $array['rr_dt'] = (new \DateTime($array['rr_dt']))->format('d.m.Y');
            $id = array_column($data["reports"], 'realizationreport_id');
            $array['isSale'] = $array["doc_type_name"] == "Продажа";
            $commission = $array['retail_amount'] * $array['commission_percent'];
            if (in_array($array['realizationreport_id'], $id)) {
                $index = array_search($array['realizationreport_id'], $id);
                $dataArray = $data["reports"][$index];
                $array['dateStart'] = min(
                    new \DateTime($data["reports"][$index]['dateStart']),
                    new \DateTime($array['rr_dt']))
                    ->format('d.m.Y');
                $array['dateEnd'] = max(
                    new \DateTime($data["reports"][$index]['dateEnd']),
                    new \DateTime($array['rr_dt']))
                    ->format('d.m.Y');
                $array['retail'] = $array['isSale'] ?
                    $dataArray['retail'] + $array['retail_amount'] :
                    $dataArray['retail'];
                $array['returned'] = !$array['isSale'] ?
                    $dataArray['returned'] - $array['retail_amount'] :
                    $dataArray['returned'];
                $array["retail_price"] = !$array['isSale'] ?
                    $dataArray["retail_price"] + $array["retail_price"] :
                    $dataArray["retail_price"];
                $array['commission'] = $array['isSale'] ? $dataArray["commission"] + $commission : $dataArray["commission"];
                $array['com_return'] = !$array['isSale'] ? $dataArray["com_return"] - $commission : $dataArray["com_return"];
                $array['com_return'] = (float)number_format($array['com_return'], 2);
                $array['commission'] = (float)number_format($array['commission'], 2);
                $data["reports"][$index] = $array;
            } else {
                $array['retail'] = $array['isSale'] ? $array['retail_amount'] : 0;
                $array['returned'] = !$array['isSale'] ? $array['retail_amount'] : 0;
                $array['commission'] = $array['isSale'] ? $commission : 0;
                $array['com_return'] = !$array['isSale'] ? -$commission : 0;
                $array['dateStart'] = $array['rr_dt'];
                $array['dateEnd'] = $array['rr_dt'];
                $data["reports"][] = $array;
            }
        }
        $context["tokens"] = $dataWb['tokens'] instanceof ApiToken ? [$dataWb['tokens']] : $dataWb['tokens'];
        $context["reports"] = (new ArrayCollection($data["reports"]))
            ->matching(Criteria::create()->orderBy(['realizationreport_id' => Criteria::DESC]))
            ->getValues();
        return $context;
    }

    public function getOrderRegion($id, $query)
    {
        $dataWb = $this->checkStatusToken($id, $query);
        $context['token'] = $dataWb['token'];

        if (!$dataWb['wbData']) {
            $context["processing"] = true;
            return $context;
        }
        $repos = $this->entityManager->getRepository(WbDataProperty::class);
        $arrayPropNames = ["wbDataOrder", "wbDataSale"];
        $arrayNames = ["orders", "sales"];
        $data = [];
        for ($i = 0; $i < count($arrayPropNames); $i++) {
            $data[$arrayNames[$i]] = $repos->getProperty($arrayPropNames[$i], $dataWb['wbData']->getId());
        }
        $data["order"] = $data["orders"];
        $data["orders"] = [];
        foreach ($data["order"] as $order) {
            $array = json_decode($order["property"], true);
            $array['oblast'] = $array['oblast'] == "" ? "Не указан" : $array['oblast'];
            $orders = array_column($data["orders"], 'city');
            $index = array_search($array['oblast'], $orders);
            if ($array['isCancel']) continue;
            if ($index) {
                $data["orders"][$index] =
                    [
                        'city' => $data["orders"][$index]['city'],
                        'quantity' => ($data["orders"][$index]['quantity']??1 + $array['quantity']??1),
                        'price' => $data["orders"][$index]['price'] + $array['totalPrice']
                    ];
            } else {
                $data["orders"][] =
                    [
                        'city' => $array['oblast'],
                        'quantity' => $array['quantity']??1,
                        'price' => $array['totalPrice']
                    ];
            }
        }
        $percent = array_sum(array_column($data['orders'], 'quantity'));
        $data["orders"] = array_map(function ($item) use ($percent) {
            $item['percent'] = number_format(($item['quantity']??1 * 100) / $percent, 1);
            return $item;
        }, $data['orders']);
        $data["sale"] = $data["sales"];
        $data["sales"] = [];
        foreach ($data["sale"] as $order) {
            $array = json_decode($order["property"], true);
            $array['regionName'] = $array['regionName'] == "" ? "Не указан" : $array['regionName'];
            $orders = array_column($data["sales"], 'city');
            $index = array_search($array['regionName'], $orders);
            if ($index) {
                $data["sales"][$index] =
                    [
                        'city' => $data["sales"][$index]['city'],
                        'quantity' => ($data["sales"][$index]['quantity']??1 + $array['quantity']??1),
                        'price' => $data["sales"][$index]['price'] + $array['totalPrice']
                    ];
            } else {
                $data["sales"][] =
                    [
                        'city' => $array['regionName'],
                        'quantity' => $array['quantity']??1,
                        'price' => $array['totalPrice']
                    ];
            }
        }
        $percent = array_sum(array_column($data['sales'], 'quantity')??1);
        $data["sales"] = array_map(function ($item) use ($percent) {
            $item['percent'] = number_format(($item['quantity']??1 * 100) / $percent, 1);
            return $item;
        }, $data['sales']);
        $context["tokens"] = $dataWb['tokens'] instanceof ApiToken ? [$dataWb['tokens']] : $dataWb['tokens'];
        $context["orders"] = (new ArrayCollection($data["orders"]))
            ->matching(Criteria::create()->orderBy(['price' => Criteria::DESC]))
            ->getValues();
        $context["sales"] = (new ArrayCollection($data["sales"]))
            ->matching(Criteria::create()->orderBy(['price' => Criteria::DESC]))
            ->getValues();
        return $context;
    }

    public function getWarehouses($id, $query)
    {
        $dataWb = $this->checkStatusToken($id, $query);
        $context['token'] = $dataWb['token'];

        if (!$dataWb['wbData']) {
            $context["processing"] = true;
            return $context;
        }

        $repos = $this->entityManager->getRepository(WbDataProperty::class);
        $arrayPropNames = ["wbDataStock"];
        $arrayNames = ["stocks"];
        $data = [];
        $city = [];
        for ($i = 0; $i < count($arrayPropNames); $i++) {
            $data[$arrayNames[$i]] = $repos->getProperty($arrayPropNames[$i], $dataWb['wbData']->getId());
        }

        $data["stock"] = $data["stocks"];
        $data["stocks"] = [];
        foreach ($data["stock"] as $stock) {
            $array = json_decode($stock["property"], true);
            $array["img"] = ((int)($array["nmId"] / 10000)) * 10000;
            if (!in_array($array['warehouseName'], array_column($city, 'name'))) {
                $city[] = ['name' => $array['warehouseName']];
            }
            $isAdd = true;
            $i = 0;
            foreach ($data["stocks"] as $stok) {
                if ($array['nmId'] == $stok['nmId'] && $array['warehouseName'] == $stok['warehouseName']) {
                    $data["stocks"][$i] = $array;
                    $isAdd = false;
                }
                $i++;
            }
            if ($isAdd) {
                $data["stocks"][] = $array;
            }
        }
        foreach ($city as $item) {
            $data["stocks"] = array_map(
                function ($items) use ($item) {
                    $city = ($items['warehouseName'] == $item['name']) ? $items['quantityFull'] : 0;
                    $items['cities'][] = $city;
                    $items['cityResult'] = ($items['cityResult'] ?? 0) + $city;
                    return $items;
                }, $data["stocks"]);
        }
        $data["stock"] = $data["stocks"];
        $count = count($data['stock']);
        $data["stocks"] = [];
        $l = 0;
        for ($i = 0; $i < $count; $i++) {
            $nmId = array_column($data["stocks"], 'nmId');
            if (!in_array($data["stock"][$i]['nmId'], $nmId)) {
                $data["stocks"][$l] = $data["stock"][$i];
                unset($data["stock"][$i]);
                $l++;
            } else {
                $index = array_search($data["stock"][$i]['nmId'], $nmId);
                $cities = $data["stock"][$i]['cities'];
                $data["stocks"][$index]['cityResult'] = 0;
                for ($j = 0; $j < count($cities); $j++) {
                    $cityValue = $cities[$j] > 0 ? $cities[$j] : $data["stocks"][$index]['cities'][$j];
                    $data["stocks"][$index]['cities'][$j] = $cityValue;
                    $data["stocks"][$index]['cityResult'] += $cityValue;
                }
            }
        }
        $data["stock"] = [];
        $context["cities"] = $city;
        $context["count"] = count($data['stocks']);
        $context["tokens"] = $dataWb['tokens'] instanceof ApiToken ? [$dataWb['tokens']] : $dataWb['tokens'];
        return array_merge($context, $data);
    }

    public function getProducts($id, $query)
    {
        $dataWb = $this->checkStatusToken($id, $query);
        $context['token'] = $dataWb['token'];

        if (!$dataWb['wbData']) {
            $context["processing"] = true;
            return $context;
        }

        $repos = $this->entityManager->getRepository(WbDataProperty::class);
        $arrayPropNames = ["wbDataStock"];
        $arrayNames = ["stocks"];
        $data = [];

        for ($i = 0; $i < count($arrayPropNames); $i++) {
            $data[$arrayNames[$i]] = $repos->getProperty($arrayPropNames[$i], $dataWb['wbData']->getId());
        }
        foreach (array_keys($data) as $datas) {
            $data[$datas] = array_map(function ($item) {
                $array = json_decode($item["property"], true);
                $array["img"] = ((int)($array["nmId"] / 10000)) * 10000;
                return $array;
            }, $data[$datas]);
        }
        $context["tokens"] = $dataWb['tokens'] instanceof ApiToken ? [$dataWb['tokens']] : $dataWb['tokens'];
        $context["products"] = $data["stocks"];
        return $context;
    }

    public function getOrders($id, $query)
    {
        $dataWb = $this->checkStatusToken($id, $query);
        $context['token'] = $dataWb['token'];

        if (!$dataWb['wbData']) {
            $context["processing"] = true;
            return $context;
        }

        $repos = $this->entityManager->getRepository(WbDataProperty::class);
        $arrayPropNames = ["wbDataOrder", "wbDataSale"];
        $arrayNames = ["orders", "sales"];
        $data = [];

        for ($i = 0; $i < count($arrayPropNames); $i++) {
            $data[$arrayNames[$i]] = $repos->getProperty($arrayPropNames[$i], $dataWb['wbData']->getId());
        }
        $count = min(count($data["orders"]), 100);
        for ($i = 0; $i < $count; $i++) {
            $array = json_decode($data["orders"][$i]["property"], true);
            $array["img"] = ((int)($array["nmId"] / 10000)) * 10000;
            $sales = array_map(function ($item) {
                return json_decode($item["property"], true);
            }, $data["sales"]);
            $sale = array_column($sales, 'orderId');
            $index = array_search($array['number']??0, $sale);
            $sale = $sales[$index];
            $array['forPay'] = !$array['isCancel'] ? $sale['forPay'] : 0;
            $array['return'] = $array['isCancel'] ? -$sale['forPay'] : 0;
            $array['commission'] = $sale['priceWithDisc'] - $sale['forPay'];
            $array['resultPay'] = !$array['isCancel'] ? $sale['forPay'] : -$sale['forPay'];
            $data["order"][$i] = $array;
        }
        $context["tokens"] = $dataWb['tokens'] instanceof ApiToken ? [$dataWb['tokens']] : $dataWb['tokens'];
        $context["orders"] = $data["order"];
        return $context;
    }

    public function deleteApiToken(ApiToken $token)
    {
        $apiTokens = $this
            ->entityManager
            ->getRepository(ApiToken::class)
            ->getTokenWithWbData($token->getToken(), false);

        if (count($apiTokens) == 1) {
            $wbData = $token->getWbData();
            if ($wbData) {
                $this->entityManager->remove($wbData);
                $this->entityManager->getRepository(WbDataProperty::class)->removeAllProp($wbData->getId());
            }
        }

        $this->entityManager->remove($token);
        $this->entityManager->flush();
    }

    public function addApiToken($user, $name, $key)
    {
        $error = '';
        if (!$key || !$name) {
            $error = "Не заполнено поле";
        } else if ($key and $name) {
            $token = $this
                ->entityManager
                ->getRepository(ApiToken::class)
                ->findBy([
                    'name' => $name,
                    'apiUser' => $user->getId(),
                    'token' => $key
                ]);
            if ($token) {
                $error = "Уже есть такой токен";
            } else {
                $user->addApiToken((new ApiToken())
                    ->setApiUser($user)
                    ->setName($name)
                    ->setToken($key)
                    ->setStatus(ApiTokenStatus::UPDATING)
                );
                $this->entityManager->flush();
                shell_exec("php ../bin/console wb:data:processing $key " . $user->getId() . " > /dev/null &");
            }
        }
        return $error;
    }

    public function getWbData($id, $query)
    {
        $dataWb = $this->checkStatusToken($id, $query);
        $context['token'] = $dataWb['token'];

        if (!$dataWb['wbData']) {
            $context["processing"] = true;
            return $context;
        }

        $repos = $this->entityManager->getRepository(WbDataProperty::class);
        $arrayPropNames = ["wbDataSale", "wbDataOrder", "wbDataStock"];
        $arrayNames = ["sales", "orders", "stocks"];
        $data = [];

        for ($i = 0; $i < count($arrayPropNames); $i++) {
            $data[$arrayNames[$i]] = $repos->getProperty($arrayPropNames[$i], $dataWb['wbData']->getId());
        }
        $context["tokens"] = $dataWb['tokens'] instanceof ApiToken ? [$dataWb['tokens']] : $dataWb['tokens'];
        $context["sales"] = $this->salesOnDay($data);
        return array_merge(
            $context,
            $this->sales($data["sales"]),
            $this->orders($data["orders"]),
            $this->stocks($data["stocks"])
        );
    }

    private function salesOnDay($datas)
    {
        $data = [];
        $date = new \DateTime();
        for ($i = 0; $i < 28; $i++) {
            $data[$i] = [
                'date' => $date->format('d.m.Y'),
                'salesQ' => 0,
                'rubS' => 0,
                'profitS' => 0,
                'costPriceS' => 0,
                'commissionS' => 0,
                'orderQ' => 0,
                'rubOr' => 0,
                'returnQ' => 0,
                'rubRet' => 0,
                'logisticToC' => 0,
                'logisticFromC' => 0,
                'fine' => 0,
            ];
            foreach ($datas["sales"] as $array) {
                $array = json_decode($array["property"], true);
                $quantity = $array["quantity"]??1;
                if (!$quantity || $quantity == 0) continue;
                $dateSale = (new \DateTime($array['date']))->format('d.m.Y');
                $dateSale = explode('.', $dateSale);
                $dateFormat = explode('.', $date->format('d.m.Y'));
                $isDate = true;
                for ($j = 0; $j < 3; $j++) {
                    $isDate = $isDate && ($dateSale[$j] == $dateFormat[$j]);
                }
                if (!$isDate) continue;
                if ($quantity < 0) {
                    $data[$i]["rubRet"] += $array["priceWithDisc"];
                    $data[$i]["returnQ"] += -$quantity;
                }
                $data[$i]['salesQ'] += $quantity;
                $rub = $array["finishedPrice"] * $quantity;
                $data[$i]['rubS'] += $rub;
                $comm = (($rub) - ($array["forPay"] * $quantity));
                $data[$i]['profitS'] += ($rub) - $comm;
                $data[$i]['commissionS'] += $comm;
            }
            foreach ($datas["orders"] as $array) {
                $array = json_decode($array["property"], true);
                $quantity = $array["quantity"]??1;
                if (!$quantity || $quantity == 0) continue;
                $dateSale = (new \DateTime($array['date']))->format('d.m.Y');
                $dateSale = explode('.', $dateSale);
                $dateFormat = explode('.', $date->format('d.m.Y'));
                $isDate = true;
                for ($j = 0; $j < 3; $j++) {
                    $isDate = $isDate && ($dateSale[$j] == $dateFormat[$j]);
                }
                if (!$isDate) continue;
                $data[$i]['orderQ'] += $quantity;
                $data[$i]['rubOr'] += $array["totalPrice"];
            }
            $date->modify("-1 day");
        }
        return $data;
    }

    public function connected($tokens, $query)
    {
        $context = [];
        if (key_exists("error", $query))
            $context['error'] = $query["error"];

        $tokens = array_map(function (ApiToken $token) {

            $wb = $token->getWbData()?->getId();
            $token = [
                'id' => $token->getId(),
                'name' => $token->getName(),
                'token' => substr($token->getToken(), 0, 15) . "...",
                'statusName' => $token->getStatusName(),
                'status' => $token->getStatus()
            ];
            if (!$wb) return $token;
            if ($token['status'] != ApiTokenStatus::ACTIVE) return $token;

            $repos = $this->entityManager->getRepository(WbDataProperty::class);
            $arrayPropNames = ["wbDataSale", "wbDataOrder", "wbDataIncome", "wbDataReport"];
            $arrayNames = ["sales", "orders", "incomes", "reports"];
            $data = [];

            for ($i = 0; $i < count($arrayPropNames); $i++) {
                $data[$arrayNames[$i]] = $repos->getProperty($arrayPropNames[$i], $wb);
            }
            $func = function ($data, $prop) {
                $data = array_map(function ($item) use ($prop) {
                    return ['date' => new \DateTime(json_decode($item['property'], true)[$prop])];
                }, $data);

                return (new ArrayCollection($data))
                    ->matching(Criteria::create()->orderBy(['date' => Criteria::DESC]))
                    ->first();
            };
            $token['turnovers'] = 0;
            $token['turnovers'] = array_sum(
                array_map(function ($item) use ($token) {
                    $item = json_decode($item['property'], true);
                    $quantity = $item['quantity']??1;
                    if ($quantity && $quantity > 0) {
                        return $item['priceWithDisc'] * $quantity;
                    }
                    return 0;
                }, $data['sales'])
            );

            $data['sales'] = $func($data['sales'], 'date');
            $data['orders'] = $func($data['orders'], 'lastChangeDate');
            $data['incomes'] = $func($data['incomes'], 'lastChangeDate');
            $data['reports'] = $func($data['reports'], 'rr_dt');

            $key = $data['sales'];
            $token['sales'] = [
                'date' => $key ? ($key['date'])->format('m.d H:i') : '00.00 00:00',
                'sale' => $key ? $key['date']->format('m.d') : '00.00'
            ];
            $key = $data['orders'];
            $token['orders'] = [
                'date' => $key ? ($key['date'])->format('m.d H:i') : '00.00 00:00',
                'order' => $key ? $key['date']->format('m.d') : '00.00'
            ];
            $key = $data['incomes'];
            $token['incomes'] = [
                'date' => $key ? ($key['date'])->format('m.d H:i') : '00.00 00:00',
                'income' => $key ? $key['date']->format('m.d') : '00.00'
            ];
            $key = $data['reports'];
            $token['reports'] = [
                'date' => $key ? ($key['date'])->format('m.d H:i') : '00.00 00:00',
                'report' => $key ? $key['date']->format('m.d') : '00.00'
            ];

            return $token;
        }, $tokens->toArray());

        $context['tokens'] = $tokens;
        return $context;
    }

    private function sales($sales)
    {
        $data["summaPrice"] = 0;
        $data["summaLength"] = 0;
        $data["summaProfit"] = 0;
        $data["summaComm"] = 0;
        $data["returnedLength"] = 0;
        $data["returnedPrice"] = 0;
        $data["rent"] = 0;
        $data["mardj"] = 0;
        foreach ($sales as $array) {
            $array = json_decode($array["property"], true);
            $quantity = $array["quantity"]??1;
            if (!$quantity || $quantity == 0) continue;
            if ($quantity < 0) {
                $data["returnedPrice"] += $array["priceWithDisc"];
                $data["returnedLength"] += $quantity;
            }
            $data["summaPrice"] += $array["priceWithDisc"] * $quantity;
            $data["summaLength"] += $quantity;
            $data["summaProfit"] += $array["finishedPrice"] * $quantity;
            $data["summaComm"] += ($array["finishedPrice"] * $quantity) - ($array["forPay"] * $quantity);
            $data["rent"] = $array["forPay"] / ($array["totalPrice"] > 0 ? $array["totalPrice"] : 1) * 100;
            $data["mardj"] = ($array["totalPrice"] - $array["forPay"]) / ($array["totalPrice"] > 0 ? $array["totalPrice"] : 1) * 100;
        }
        $data["rent"] = $data["rent"] > 0 && $data["rent"] < 100 ? $data["rent"] : 28;
        $data["mardj"] = $data["mardj"] > 0 && $data["mardj"] < 100 ? $data["mardj"] : 52;
        return $data;
    }

    private function orders($orders)
    {
        $data["ordersPrice"] = 0;
        $data["ordersLength"] = 0;
        foreach ($orders as $array) {
            $array = json_decode($array["property"], true);
            $quantity = $array["quantity"]??1;
            if (!$quantity || $quantity == 0) continue;
            $data["ordersPrice"] += ($array["totalPrice"] * $quantity);
            $data["ordersLength"] += $quantity;
        }
        return $data;
    }

    private function stocks($stocks)
    {
        $data["costPrice"] = 0;
        $data["retailPrice"] = 0;
        foreach ($stocks as $array) {
            $array = json_decode($array["property"], true);
            $quantity = $array["quantity"]??1;
            if (!$quantity || $quantity == 0) continue;
            $data["costPrice"] += ($array["Price"] * $quantity * $array["Discount"]) / 100;
            $data["retailPrice"] += ($array["Price"] * $quantity);
        }
        return $data;
    }
}
