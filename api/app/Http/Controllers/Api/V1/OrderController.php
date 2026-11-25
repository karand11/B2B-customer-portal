<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListOrdersRequest;
use App\Http\Requests\PlaceOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\CartResource;
use App\Http\Resources\OrderDetailResource;
use App\Http\Resources\OrderListResource;
use App\Models\Order;
use App\Services\CartService;
use App\Services\OrderStatusChanger;
use App\Services\PlaceOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Every order lookup goes through Order::visibleTo() and then the policy (two layers; foreign = 404). */
class OrderController extends Controller
{
    public function index(ListOrdersRequest $request): OrderListResource
    {
        $c = $request->criteria();
        $orders = Order::visibleTo($request->user())
            ->filter($c)
            ->with(['company:id,name', 'createdBy:id,name'])
            ->withCount('items as line_count')
            ->paginate($c['per_page'], ['*'], 'page', $c['page']);

        return new OrderListResource($orders);
    }

    public function show(Request $request, string $number): OrderDetailResource
    {
        $order = $this->find($request, $number);
        $this->authorize('view', $order);

        return new OrderDetailResource($this->detail($order));
    }

    public function store(PlaceOrderRequest $request, PlaceOrder $place): JsonResponse
    {
        $this->authorize('create', Order::class);
        $order = $place->handle($request->user(), $request->poReference());

        return (new OrderDetailResource($this->detail($order)))->response()->setStatusCode(201);
    }

    public function cancel(Request $request, string $number, OrderStatusChanger $changer): OrderDetailResource
    {
        $order = $this->find($request, $number);
        $this->authorize('cancel', $order);

        return new OrderDetailResource($this->detail($changer->change($order, OrderStatus::Cancelled, $request->user())));
    }

    public function status(UpdateOrderStatusRequest $request, string $number, OrderStatusChanger $changer): OrderDetailResource
    {
        $order = $this->find($request, $number);
        $this->authorize('transition', $order);
        $to = OrderStatus::from($request->validated('status'));

        return new OrderDetailResource($this->detail($changer->change($order, $to, $request->user())));
    }

    public function reorder(Request $request, string $number, CartService $cart): JsonResponse
    {
        $order = $this->find($request, $number);
        $this->authorize('reorder', $order);
        $result = $cart->reorder($request->user(), $order->load('items'));

        return response()->json(['data' => ['cart' => (new CartResource($cart->items($request->user())))->resolve()] + $result]);
    }

    private function find(Request $request, string $number): Order
    {
        return Order::visibleTo($request->user())->where('order_number', strtoupper($number))->firstOrFail();
    }

    /** Everything OrderDetailResource reads, loaded in a constant number of queries. */
    private function detail(Order $order): Order
    {
        return $order->load(['items', 'history.actor.roles', 'company:id,name', 'createdBy:id,name']);
    }
}
