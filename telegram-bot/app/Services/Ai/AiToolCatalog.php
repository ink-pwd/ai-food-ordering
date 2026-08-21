<?php

namespace App\Services\Ai;

use App\DTO\Llm\LlmToolDefinitionData;
use App\DTO\Llm\LlmToolParameterData;

final class AiToolCatalog
{
    /** @return list<LlmToolDefinitionData> */
    public function definitions(): array
    {
        return [
            new LlmToolDefinitionData(
                name: 'search_products',
                description: 'Search products in the selected restaurant using case-insensitive substring matching. If a full phrase returns no products, retry with a shorter distinctive word or stable word fragment before concluding that nothing was found.',
                parameters: [
                    new LlmToolParameterData(
                        name: 'query',
                        type: 'string',
                        description: 'Short product phrase, distinctive word, or stable word fragment from the user wording. Do not pass the whole sentence.',
                    ),
                ],
            ),
            new LlmToolDefinitionData(
                name: 'get_cart',
                description: 'Get the current cart and its items.',
            ),
            new LlmToolDefinitionData(
                name: 'add_to_cart',
                description: 'Add a known product to the current cart. Use search_products first when the product id is unknown.',
                parameters: [
                    new LlmToolParameterData(
                        name: 'product_id',
                        type: 'integer',
                        description: 'Exact product id returned by search_products.',
                        minimum: 1,
                    ),
                    new LlmToolParameterData(
                        name: 'quantity',
                        type: 'integer',
                        description: 'Quantity to add to the current quantity.',
                        minimum: 1,
                    ),
                ],
            ),
            new LlmToolDefinitionData(
                name: 'set_cart_item_quantity',
                description: 'Set the final quantity for an existing cart item. Use get_cart first to obtain item_id.',
                parameters: [
                    new LlmToolParameterData(
                        name: 'item_id',
                        type: 'integer',
                        description: 'Cart item id returned by get_cart.',
                        minimum: 1,
                    ),
                    new LlmToolParameterData(
                        name: 'quantity',
                        type: 'integer',
                        description: 'Final desired quantity, at least 1.',
                        minimum: 1,
                    ),
                ],
            ),
            new LlmToolDefinitionData(
                name: 'remove_cart_item',
                description: 'Remove an existing item from the cart. Use get_cart first to obtain item_id.',
                parameters: [
                    new LlmToolParameterData(
                        name: 'item_id',
                        type: 'integer',
                        description: 'Cart item id returned by get_cart.',
                        minimum: 1,
                    ),
                ],
            ),
            new LlmToolDefinitionData(
                name: 'get_order_tracking',
                description: 'Get safe tracking information for a local order id shown to the user after checkout.',
                parameters: [
                    new LlmToolParameterData(
                        name: 'order_id',
                        type: 'integer',
                        description: 'Local order number shown by the Telegram bot.',
                        minimum: 1,
                    ),
                ],
            ),
        ];
    }
}
