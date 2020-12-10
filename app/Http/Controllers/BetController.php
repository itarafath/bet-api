<?php

namespace App\Http\Controllers;

use App\Models\BalanceTransaction;
use App\Models\Bet;
use App\Models\BetSelection;
use App\Models\Player;
use Illuminate\Http\Request;
use Response;

class BetController extends Controller
{
    public function store(Request $request)
    {
        $player = Player::find($request->player_id);
        if (!$player) {
            Player::create(['id' => $request->player_id]);
            $player = Player::find($request->player_id);
        }

        list($globalError, $selectionError) = $this->validateError($request, $player);
        if (count($globalError) || count($selectionError)) {
            return $this->errorResponse($globalError, $selectionError);
        }


        $bet = Bet::create([
            'player_id' => $player->id,
            'stake_amount' => $request->stake_amount
        ]);

        $rows = [];
        foreach ($request->get('selections') as $selection) {
            array_push($rows, [
                'bet_id' => $bet->id,
                'selection_id' => $selection['id'],
                'odds' => $selection['odds'],
            ]);
        }
        BetSelection::insert($rows);

        $amount = $player->balance - $request->stake_amount;
        BalanceTransaction::create([
            'player_id' => $player->id,
            'amount' => $amount,
            'amount_before' => $player->balance
        ]);

        $player->where('id',$player->id)->update(['balance'=>$amount]);

    }

    public function errorResponse($errors, $selections)
    {
        return Response::json([
            'errors' => $errors,
            'selections' => $selections
        ], 404);
    }

    public function successResponse()
    {
        return Response::json([
            'success' => true,
            'message' => ''
        ], 201);
    }

    private function validateError(Request $request, $player)
    {
        $globalError = [];
        $selectionError = [];
        if (!$request->has(['player_id', 'stake_amount', 'selections'])) {
            array_push($globalError, $this->globalValidate(1));
            return $this->errorResponse($globalError, []);
        }

        $stake_amount = $request->get('stake_amount');
        if ($stake_amount < 0.3) {
            array_push($globalError, $this->globalValidate(2));
        }

        if ($stake_amount > 10000) {
            array_push($globalError, $this->globalValidate(3));
        }

        $selections = $request->get('selections');
        if (count($selections) < 1) {
            array_push($globalError, $this->globalValidate(4));
        }

        if (count($selections) > 20) {
            array_push($globalError, $this->globalValidate(5));
        }

        $max_win_amount = $stake_amount * array_product(array_column($selections, 'odds'));
        if ($max_win_amount > 20000) {
            array_push($globalError, $this->globalValidate(9));
        }

        if ($player->balance < $stake_amount) {
            array_push($globalError, $this->globalValidate(11));
        }

        $stack = [];
        foreach ($selections as $selection) {
            $odds = $selection['odds'];
            $id = $selection['id'];

            if ($odds < 1) {
                array_push($selectionError, $this->selectionValidate(6, $id));
            }

            if ($odds > 10000) {
                array_push($selectionError, $this->selectionValidate(7, $id));
            }

            if (in_array($id, $stack)) {
                array_push($selectionError, $this->selectionValidate(8, $id));
            }

            array_push($stack, $id);

        }

        return [$globalError, $selectionError];
    }

    private function globalValidate($code): array
    {
        switch ($code) {
            case 0:
                $message = "Unknown error";
                break;
            case 1:
                $message = "Betslip structure mismatch";
                break;
            case 2:
                $message = 'Minimum stake amount is 0.3';
                break;
            case 3:
                $message = 'Maximum stake amount is 10000';
                break;
            case 4:
                $message = 'Minimum number of selections is 1';
                break;
            case 5:
                $message = 'Maximum number of selections is 20';
                break;
            case 9:
                $message = 'Maximum win amount is 20000';
                break;
            case 10:
                $message = 'Your previous action is not finished yet';
                break;
            case 11:
                $message = 'Insufficient balance';
                break;
            default:
                $message = "Unknown error";
                $code = 0;
        }

        return [
            "code" => $code,
            "message" => $message
        ];
    }

    private function selectionValidate($code, $id): array
    {
        switch ($code) {
            case 6:
                $message = 'Minimum odds are 1';
                break;
            case 7:
                $message = 'Maximum odds are 10000';
                break;
            case 8:
                $message = 'Duplicate selection found';
                break;
            default:
                $message = "Unknown error";
                $code = 0;
        }

        return [
            'id' => $id,
            'errors' => [
                "code" => $code,
                "message" => $message
            ]
        ];
    }
}
