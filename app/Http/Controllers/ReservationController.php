<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReservationController extends Controller
{

    public function store(Request $request)
    {
        $validatedData = Validator::make($request->all(), [
            'user_id' => 'required',
            'hotel_id' => 'required',
            'room_id' => 'required',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'number_of_guests' => 'required',
        ]);

        if ($validatedData->fails()) {
            return response()->json($validatedData->errors(), 400);
        }

        // Check if user exists
        $findUser = User::find($request->user_id);
        if (is_null($findUser)) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Check if hotel exists
        $findHotel = Hotel::find($request->hotel_id);
        if (is_null($findHotel)) {
            return response()->json(['message' => 'Hotel not found'], 404);
        }

        // Check if room exists
        $findRoom = Room::find($request->room_id);
        if (is_null($findRoom)) {
            return response()->json(['message' => 'Room not found'], 404);
        }

        // Check room availability
        $roomAvailable = $this->checkRoomAvailability($request->room_id, $request->start_date, $request->end_date);
        if (!$roomAvailable) {
            return response()->json(['message' => 'The reservation for the required room is not available for the selected dates'], 400);
        }
        $start_date = new DateTime($request->start_date);
        $end_date = new DateTime($request->end_date);
        $interval = $start_date->diff($end_date);
        $days_difference = $interval->days;
        $total_amount = $findRoom->price * $days_difference;

        // Create booking
        try {
            DB::beginTransaction();
            $data = [
                'room_id' => $request->room_id,
                'user_id' => $request->user_id,
                'hotel_id' => $request->hotel_id,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'number_of_guests' => $request->number_of_guests,
                'total_amount' => $total_amount,
            ];
            if($request->special_request){
                $data['special_request'] = $request->special_request;
            }
            $reservation_done = Reservation::create($data);
            DB::commit();
            return response()->json(['message' => 'Reservation created successfully', 'data' => $reservation_done], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Internal server error', 'error' => $e->getMessage()], 500);
        }
    }

    protected function checkRoomAvailability($roomId, $startDate, $endDate, $excludeBookingId = null)
    {
        $bookingsQuery = Booking::where('room_id', $roomId)
            ->where(function ($query) use ($startDate, $endDate, $excludeBookingId) {
                $query->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($query) use ($startDate, $endDate) {
                        $query->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
                if ($excludeBookingId !== null) {
                    $query->where('id', '<>', $excludeBookingId);
                }
                $query->where('status', '=', 1);
            });

        $reservationsQuery = Reservation::where('room_id', $roomId)
            ->where(function ($query) use ($startDate, $endDate, $excludeBookingId) {
                $query->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($query) use ($startDate, $endDate) {
                        $query->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
                if ($excludeBookingId !== null) {
                    $query->where('id', '<>', $excludeBookingId);
                }
                $query->whereIn('status', ['pending', 'confirmed']);
            });

        $bookingsCount = $bookingsQuery->count();
        $reservationsCount = $reservationsQuery->count();

        return $bookingsCount === 0 && $reservationsCount === 0;
    }



    public function show()
    {
        return response()->json([
            'All reservation details' => [
                'reservation' => Reservation::all()
            ],
            'status' => 1
        ], 200);
    }

    public function index($id){
        $findReservation=Reservation::find($id);
        if(is_null($findReservation)){
            $response=[
               'message' => 'Reservation not exists!',
               'status' => 0,
             ];
             $errorCode=401;
        }else{
                $response =[
                    'reservation' => $findReservation,
                    'status' => 1
                ];
                $errorCode=200;
            }
        return response()->json($response,$errorCode);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors(),
                'status' => 0
            ], 400);
        }

        $reservation = Reservation::find($id);
        if (is_null($reservation)) {
            return response()->json(['message' => 'Reservation not found'], 404);
        }
        $findRoom = $reservation->room_id;
        $findRoom=Room::find($findRoom);

        $roomAvailable = $this->checkRoomAvailability($reservation->room_id, $request->start_date, $request->end_date, $id);

        if (!$roomAvailable) {
            return response()->json(['message' => 'The reservation for the requested room is not available for the updated dates'], 400);
        }


        DB::beginTransaction();
        try {
            $start_date = new DateTime($request->start_date);
            $end_date = new DateTime($request->end_date);
            $interval = $start_date->diff($end_date);
            $days_difference = $interval->days;
            $total_amount = $findRoom->price * $days_difference;

            $data=[
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'number_of_guests' => $request->number_of_guests,
                'total_amount' => $total_amount,
                'special_request' => $request->special_request
            ];
            $reservation->update($data);
            DB::commit();
            $response = [
                'message' => 'Reservation Updated Successfully',
                'data' => $reservation,
                'status' => 1
            ];
            $errorCode = 200;
        } catch (\Exception $e) {
            DB::rollBack();
            $response = [
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
                'status' => 0
            ];
            $errorCode = 500;
        }
        return response()->json($response, $errorCode);
    }

    public function cancel($id)
    {
        $reservation = Reservation::find($id);

        if (is_null($reservation)) {
            return response()->json(['message' => 'Reservation not found'], 404);
        }

        if ($reservation->status == 'pending' || $reservation->status == 'confirmed') {
            $reservation->status = 'canceled';
            $reservation->save();

            return response()->json(['message' => 'Reservation canceled successfully'], 200);
        } else {
            return response()->json(['message' => 'Reservation cannot be canceled'], 400);
        }
    }

    public function destroy($id)
    {
        $reservation = Reservation::find($id);

        if (is_null($reservation)) {
            return response()->json(['message' => 'Reservation not found'], 404);
        }

        DB::beginTransaction();
        try {
            $reservation->delete();
            DB::commit();
            $response = [
                'message' => 'Reservation Deleted Successfully',
                'status' => 1
            ];
            $errorCode = 200;
        } catch (\Exception $e) {
            DB::rollBack();
            $response = [
                'message' => 'Internal server error',
                'error' => $e->getMessage(),
                'status' => 0
            ];
            $errorCode = 500;
        }

        return response()->json($response, $errorCode);
    }

    public function confirm($id)
    {
        $reservation = Reservation::find($id);

        if (!$reservation) {
            return response()->json(['message' => 'Reservation not found'], 404);
        }

        if ($reservation->status == 'pending') {
            $reservation->status = 'confirmed';
            $reservation->save();
            return response()->json(['message' => 'Reservation confirmed successfully'], 200);
        }else{
            return response()->json(['message' => 'Reservation cannot be confirmed'], 400);
        }
    }
}
