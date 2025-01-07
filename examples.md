# Qwirkle API Examples

## Authentication
```bash
# Authenticate as player1
curl -X POST http://localhost:88/qwirkle/api/games \
  -H "Authorization: Basic $(echo -n 'player1:' | base64)"
```

## Create Game
```bash
# Create a new game
curl -X POST http://localhost:88/qwirkle/api/games \
  -H "Authorization: Basic $(echo -n 'player1:' | base64)"
```

## Join Game
```bash
# Join game with ID 123
curl -X POST http://localhost:88/qwirkle/api/games/123/join \
  -H "Authorization: Basic $(echo -n 'player2:' | base64)"
```

## Make Move
```bash
# Place a tile
curl -X POST http://localhost:88/qwirkle/api/games/123/moves \
  -H "Authorization: Basic $(echo -n 'player1:' | base64)" \
  -H "Content-Type: application/json" \
  -d '{
    "move_type": "place",
    "move_data": {
      "tiles": [
        {
          "x": 0,
          "y": 0,
          "tile": "red_circle"
        }
      ]
    }
  }'

# Exchange tiles
curl -X POST http://localhost:88/qwirkle/api/games/123/moves \
  -H "Authorization: Basic $(echo -n 'player1:' | base64)" \
  -H "Content-Type: application/json" \
  -d '{
    "move_type": "exchange",
    "move_data": {
      "tiles": ["red_circle", "blue_star"]
    }
  }'

# Pass turn
curl -X POST http://localhost:88/qwirkle/api/games/123/moves \
  -H "Authorization: Basic $(echo -n 'player1:' | base64)" \
  -H "Content-Type: application/json" \
  -d '{
    "move_type": "pass"
  }'
```

## Get Game State
```bash
# Get current game state
curl -X GET http://localhost:88/qwirkle/api/games/123 \
  -H "Authorization: Basic $(echo -n 'player1:' | base64)"
```

## Complete Game Flow Example
```bash
# 1. Player 1 creates game
GAME_ID=$(curl -X POST http://localhost:88/qwirkle/api/games \
  -H "Authorization: Basic $(echo -n 'player1:' | base64)" \
  | jq -r '.game_id')

# 2. Player 2 joins game
curl -X POST http://localhost:88/qwirkle/api/games/$GAME_ID/join \
  -H "Authorization: Basic $(echo -n 'player2:' | base64)"

# 3. Player 1 makes first move
curl -X POST http://localhost:88/qwirkle/api/games/$GAME_ID/moves \
  -H "Authorization: Basic $(echo -n 'player1:' | base64)" \
  -H "Content-Type: application/json" \
  -d '{
    "move_type": "place",
    "move_data": {
      "tiles": [
        {
          "x": 0,
          "y": 0,
          "tile": "red_circle"
        }
      ]
    }
  }'

# 4. Get game state
curl -X GET http://localhost:88/qwirkle/api/games/$GAME_ID \
  -H "Authorization: Basic $(echo -n 'player1:' | base64)"
``` 