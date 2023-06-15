<?php
namespace App\Core\Gateways;

use VoipUnlimited\LaravelCore\Base\BaseGateway;
use App\Core\Repositories\Markup\MarkupRepository;

use App\Core\Services\{
    NeosService,
    TalkTalkService,
    CityFibreService,
    VirginMediaServices,
    ColtService,
    SkyService,
    BTService,
    ITSService
};
use Illuminate\Http\Request;
use App\Http\Requests\ProvidersRequest;
use App\Core\Repositories\EPTRequest\EPTRequestRepository;
use App\Core\Repositories\Providers\ProvidersRepository;
use App\Core\Repositories\ProviderCupids\ProviderCupidsRepository;
use Ramsey\Uuid\Type\Decimal;

class ProviderGateway extends BaseGateway
{
    public $providersIdentification;
    public $neosAPI;
    public $cityFibre;
    public $virginMedia;
    public $colt;
    public $talkTalk;
    public $sky;
    public $bt;
    public $markupRepository;
    public $its;
    public $quoteGateway;
    public $providersRepository;
    public $eptRequestRepository;
    public $providerCupidsRepository;

    public function __construct(
        NeosService          $neosAPI,
        CityFibreService     $cityFibre,
        VirginMediaServices  $virginMedia,
        ColtService          $colt,
        TalkTalkService      $talkTalk,
        SkyService           $sky,
        BTService            $bt,
        ITSService           $its,
        MarkupRepository     $markupRepository,
        QuoteGateway         $quoteGateway,
        ProvidersRepository  $providersRepository,
        EPTRequestRepository $eptRequestRepository,
        ProviderCupidsRepository $providerCupidsRepository
    )
    {
        $this->neosAPI = $neosAPI;
        $this->cityFibre = $cityFibre;
        $this->virginMedia = $virginMedia;
        $this->colt = $colt;
        $this->talkTalk = $talkTalk;
        $this->sky = $sky;
        $this->bt = $bt;
        $this->markupRepository = $markupRepository;
        $this->its = $its;
        $this->quoteGateway = $quoteGateway;
        $this->providersRepository = $providersRepository;
        $this->eptRequestRepository = $eptRequestRepository;
        $this->providerCupidsRepository = $providerCupidsRepository;

        $this->providersIdentification = [
            config('constant.NEOS_UIID') => 'neosAPI',
            config('constant.TALKTALK_UIID') => 'TalkTalkAPI',
            config('constant.CITYFIBRE_UIID') => 'cityFibreAPI',
            config('constant.VIRGINMEDIA_UIID') => 'virginMediaAPI',
            config('constant.COLT_UIID') => 'coltAPI',
            config('constant.SKY_UIID') => 'skyAPI',
            config('constant.BT_UIID') => 'btAPI',
            config('constant.ITS_UIID') => 'itsAPI',
        ];
    }

    public function providerDetails(ProvidersRequest $payload, $debug): array
    {
        // Validate Provider
        if (!array_key_exists($payload->provider, $this->providersIdentification)) {
            return [
                'message' => 'Invalid Provider',
                'data' => []
            ];
        }

        // Dynamically Call Method
        $func = $this->providersIdentification[$payload->provider];

        return self::$func($payload, $debug);
    }

    public function filterUpTo3YearsContracts($quotes) // Once we start offering 5 years term contract we won't be using this function
    {
        $quotes = collect($quotes)->filter(function ($quote) {
            return $quote['term'] <= 3;
        });

        return $quotes->toArray();
    }

    public function addMarkups($payload)
    {
        $request = $this->eptRequestRepository->getByRequestId($payload['request_id']);

        $markupData = $this->markupRepository->getMarkup([
            'user_type' => $request['quote_type_id'] ?? 3,
            'supplier_id' => $payload['provider'],
        ]);

        return $markupData;
    }


    public function addInstallationRentalMarkup($apiResponse, $markup)
    {
        $sellData = collect($apiResponse)
            ->filter(function ($item) use ($markup) {
                $term = $item['term'] . 'yr';
                if (!is_null($markup['connection_addition_' . $term]) ||
                    !is_null($markup['connection_markup_' . $term]) ||
                    !is_null($markup['rental_addition_' . $term]) ||
                    !is_null($markup['rental_markup_' . $term])
                ) {
                    return $item;
                }
            })
            ->map(function ($item) use ($markup) {
                $term = $item['term'];

                $connectionSell = ($item['costs']['connection'] + $markup['connection_addition_' . $term . 'yr']) * (1 + $markup['connection_markup_' . $term . 'yr'] / 100);
                $rentalSell = ($item['costs']['rental'] + $markup['rental_addition_' . $term . 'yr']) * (1 + $markup['rental_markup_' . $term . 'yr'] / 100);

                $item['costs']['sell'] = [
                    'connection' => number_format($connectionSell, 2, ".", ""),
                    'rental' => number_format($rentalSell, 2, ".", ""),
                ];

                //change the old key names (installation and recurring)
                unset($item['costs']['connection'], $item['costs']['rental']); // for renaming the old keys

                $item['user_type'] = $markup['user_type'];

                return $item;
            })->values();

        return $sellData;

    }

    public function processDataForQuotes($quotes, $payload)
    {
        $upTo3YearsQuotes = $this->filterUpTo3YearsContracts($quotes);
        //Once we start offering 5 years term contract, we won't be using $upTo3YearsQuptes
        $saveQuotes = $this->quoteGateway->createQuote($upTo3YearsQuotes, $payload);
        //Once we start offering 5 years term contract, update $saveQuotes accordingly
        $markup = $this->addMarkups($payload);
        $sellData = $this->addInstallationRentalMarkup($saveQuotes, $markup);
        $quotes = json_decode(json_encode($sellData->values()), true);

        return $quotes;
    }


    public function neosAPI(ProvidersRequest $payload, $debug): array
    {
        $neosReturnData = [];
        $neosYearContract = isset($payload->contract_term) ? $payload->contract_term: [1, 3];

        for ($i = 0; $i < count($neosYearContract); $i++) {
            $neosResponse = $this->neosAPI->postQuotationDetails($payload, $neosYearContract[$i], $debug);
            if (!array_key_exists('message', $neosResponse)) {
                for($j = 0; $j < count($neosResponse); $j ++){
                    array_push($neosReturnData, $neosResponse[$j]);
                }
            } else {
                return $neosResponse;
            }
        }

        return $debug ? $neosReturnData: $this->processDataForQuotes($neosReturnData, $payload);
    }

    public function TalkTalkAPI(ProvidersRequest $payload, $debug): array
    {
        $talkTalk = $this->talkTalk->postQuotationDetails($payload, $debug);

        if (!isset($talkTalk['message']) && !$debug) {

            return $this->processDataForQuotes($talkTalk, $payload);
        }

        return $talkTalk;
    }

    public function cityFibreAPI(ProvidersRequest $payload, $debug): array
    {
        $cityFibre = $this->cityFibre->postQuotationDetails($payload, $debug);

        if (!isset($cityFibre['error']) && !isset($cityFibre['message']) && !$debug) {

            $cityFibre = collect($cityFibre)
                ->filter(function ($quote) {

                    if ($quote['product']['type'] !== 'GPON' ) {
                        return $quote;
                    }
                })
                ->map(function ($quote) {
                    $quote['costs']['rental'] = $quote['costs']['rental'] * 12;
                    return $quote;
                })
                ->toArray();

            return $this->processDataForQuotes($cityFibre, $payload);
        }

        return $cityFibre;
    }


    public function virginMediaAPI(ProvidersRequest $payload): array
    {
        $virginMediaReturnData = [];
        $virginMediaYearContract = isset($payload->contract_term) ? $payload->contract_term: [1, 3];

        for ($i = 0; $i < count($virginMediaYearContract); $i++) {
            $virginMediaResponse = $this->virginMedia->postQuotationDetails($payload, $virginMediaYearContract[$i]);
            if (count($virginMediaResponse) > 0) {
                array_push($virginMediaReturnData, $virginMediaResponse);
            }
            $this->quoteGateway->createQuote($virginMediaReturnData, $payload);
        }

        //Currently, can't test the Vigin media api response, work in progress.
        return $virginMediaReturnData;
    }

    public function coltAPI(ProvidersRequest $payload): array
    {
        $coltReturnData = $this->colt->postQuotationDetails($payload);
        $this->quoteGateway->createQuote($coltReturnData, $payload);

        return $coltReturnData;
        //Currently, can't test the Colt api response, work in progress.
    }

    public function skyAPI(ProvidersRequest $payload, $debug): array
    {
        $skyResponse = $this->sky->postQuotationDetails($payload, $debug);
        if (!isset($skyResponse['message']) && !$debug) {
            return $this->processDataForQuotes($skyResponse, $payload);
        }

        return $skyResponse;
    }

    public function btAPI(ProvidersRequest $payload, $debug): array
    {
        $btReturnData = $this->bt->postQuotationDetails($payload, $debug);
        if (!isset($btReturnData['message']) && !$debug) {
            return $this->processDataForQuotes($btReturnData, $payload);
        }

        return $btReturnData;
    }

    public function itsAPI(ProvidersRequest $payload, $debug): array
    {
        $its = $this->its->postQuotationDetails($payload, $debug);

        if (!isset($its['message']) && !$debug) {
            return $this->processDataForQuotes($its, $payload);
        }

        return $its;
    }

    public function getProviders()
    {
        return collect($this->providersRepository->getAll())
                ->filter(function($provider){
                    if($provider->active){
                        return($provider->active);
                    }
                })
                ->values()
                ->toArray();
    }

    public function getAllProviders()
    {
        return $this->providersRepository->getAll();
    }

    public function getProviderCupids($providerId)
    {
        return $this->providerCupidsRepository->getProviderCupids($providerId);
    }
}
