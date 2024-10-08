<?php
require_once 'vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

function generateRecipe($ingredients,$dietaryRestriction) {
    $api_token = $_ENV['REPLICATE_API_TOKEN'];
    $ingredients_list = implode(', ', $ingredients);

    $data = [
        'input' => [
            'prompt' => "$ingredients_list",
            'max_tokens' => 256,
            'length_penalty' => 1,
            'system_prompt' => "
                Generate one recipe that includes the following ingredients: $ingredients_list and is suitable for the specified dietary restriction: $dietaryRestriction.

**Formatting Requirements:**
1. Start with the recipe title on the first line (do not include any introductory text).
2. On a new line, write 'Ingredients:' and then list all ingredients in a bulleted format (e.g., - 1 cup flour).
3. On the next line, write 'Instructions:' and then list the cooking steps in a numbered format (e.g., 1. Preheat the oven to 350°F).
4. At the end, write 'END' on a new line.

**Example Format:**
Recipe Title

Ingredients:
- Ingredient 1
- Ingredient 2

Instructions:
1. Step one.
2. Step two.

END

**Important:**
- Include the title, ingredients, and instructions as specified.
- Do not include any additional text or explanations.",
            'prompt_template' => "``\n\n{system_prompt}\n\n{prompt}\n\n``",
            'stop_sequences' => "END",
            'temperature' => 1,
        ]
    ];

    $ch = curl_init('https://api.replicate.com/v1/models/meta/meta-llama-3-8b-instruct/predictions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $api_token",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);

    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $prediction = json_decode($response, true);

    if (!isset($prediction['id'])) {
        return "Error: Unable to start prediction. Response: " . print_r($prediction, true);
    }

    // Poll for the result
    $start_time = time();
    while (true) {
        sleep(2); // Wait 2 seconds between checks
        $ch = curl_init("https://api.replicate.com/v1/predictions/{$prediction['id']}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $api_token"]
        ]);
        $response = curl_exec($ch);
        $status = json_decode($response, true);
        curl_close($ch);
        

        if ($status['status'] === 'succeeded') {
            if (is_array($status['output'])) {
                array_shift($status['output']); // Remove the first token permanently
                return implode('', $status['output']); // Return the remaining tokens as a string
            } else {
                return (string)$status['output']; // Ensure output is treated as a string
            }
        } elseif ($status['status'] === 'failed') {
            return "Error: Prediction failed. " . ($status['error'] ?? 'Unknown error');
        }

        if (time() - $start_time > 60) { // 60 seconds timeout
            return "Error: Prediction timed out";
        }
    }
}

function cleanRecipeText($text) {
    // Remove extra spaces and newlines
    $text = preg_replace('```([^`]*)```','',$text);
    $text = preg_replace('"([^`]*)"','',$text);
    $text = str_replace('`', '', $text); // Remove backticks
    $text = str_replace('```', '', $text); // Remove backticks
    $text = str_replace('Recipe: ', '', $text); // Remove backticks   
    $text = preg_replace('/\s+/', ' ', $text); // Replace multiple spaces with a single space
    $text = preg_replace('/\n+/', ' ', $text); // Replace multiple newlines with a single space
    $text = trim($text); // Trim leading and trailing spaces
    return $text;
}

function generateRecipeImage($recipeTitle,$ingredientsText) {
    $api_token = $_ENV['REPLICATE_API_TOKEN'];

    $data = [
        'input' => [
            'prompt' => "Photo of $recipeTitle taken with a Sony A7III camera for a Food & Wine Magazine. Make sure to include the main ingredients from $ingredientsText",
            'num_outputs' => 1,
            'aspect_ratio' => "1:1",
            'output_format' => "webp",
            'output_quality' => 90
        ]
    ];

    $ch = curl_init('https://api.replicate.com/v1/models/black-forest-labs/flux-schnell/predictions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $api_token",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);

    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_status !== 201) {
        return "Error: HTTP status $http_status. Response: $response";
    }

    $prediction = json_decode($response, true);
    if (!isset($prediction['id'])) {
        return "Error: Unable to start image generation. Response: " . print_r($prediction, true);
    }

    // Poll for the result
    $start_time = time();
    while (true) {
        sleep(2); // Wait 2 seconds between checks
        $ch = curl_init("https://api.replicate.com/v1/predictions/{$prediction['id']}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $api_token"]
        ]);
        $response = curl_exec($ch);
        $status = json_decode($response, true);
        curl_close($ch);

        if ($status['status'] === 'succeeded') {
            return $status['output'][0]; // Return the URL of the generated image
        } elseif ($status['status'] === 'failed') {
            return "Error: Image generation failed. " . ($status['error'] ?? 'Unknown error');
        }

        if (time() - $start_time > 60) { // 60 seconds timeout
            return "Error: Image generation timed out";
        }
    }
}

$recipe = '';
$imageUrl = '';
$recipeGenerated = false; // Flag to check if the recipe is generated

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ingredients = explode(',', $_POST['ingredients']);
    $ingredients = array_map('trim', $ingredients);
     // Get the selected dietary restriction
    $dietaryRestriction = $_POST['dietaryRestrictions'];
    
    // Generate the recipe based on dietary restrictions
    $recipe = generateRecipe($ingredients, $dietaryRestriction);
    
    // Assuming $recipe contains the full recipe text
    $splitPattern = '/Ingredients:/'; // Pattern to split on "Ingredients" 
    $parts = preg_split($splitPattern, $recipe, 2); // Split the recipe into two parts

    if (count($parts) > 1) {
        $recipeTitle = trim($parts[0]); // Get the text before "Ingredients" or "-"
        $recipeTitle = preg_replace('/[-=]{2,}|[*]+/', '', $recipeTitle); // Remove "----" or "===="
        $ingredientsText = trim($parts[1]); // Get the text after "Ingredients" or "-"
    } else {
        $recipeTitle = trim($recipe); // Fallback to the entire recipe if no split occurs
        $ingredientsText = ''; // No ingredients found
    }

    // Find the position of "Instructions" to separate instructions
    $instructionsPosition = strpos($ingredientsText, 'Instructions'); // Find the position of "Instructions"

    if ($instructionsPosition !== false) {
        $instructionsText = trim(substr($ingredientsText, $instructionsPosition + strlen('Instructions:'))); // Get the text after "Instructions:"
        $ingredientsText = trim(substr($ingredientsText, 0, $instructionsPosition)); // Get the ingredients text
    } else {
        $instructionsText = ''; // No instructions found
    }

    // Split ingredients and instructions
    $ingredientsArray = explode("\n", $ingredientsText);
    $instructionsArray = explode("\n", $instructionsText);

    // Generate the image using the recipe title
    $imageUrl = generateRecipeImage($recipeTitle,$ingredientsText);
    $recipeGenerated = true; // Set the flag to true

    // Show the output section by setting it to display block
    echo"<script>
                function updateRecipeSidebar() {
                const sidebar = document.getElementById('recipeSidebar');
                if (!sidebar) {
                    console.error('Sidebar element not found');
                    return; // Exit if the sidebar is not found
                }
                sidebar.innerHTML = ''; // Clear existing sidebar content
                const recipes = JSON.parse(localStorage.getItem('recentRecipes')) || [];
                recipes.forEach((recipe) => {
                    const listItem = document.createElement('li');
                    listItem.innerText = recipe.title;
                    listItem.onclick = () => viewRecipe(recipe); // Set click handler
                    sidebar.appendChild(listItem);
                });
            };
            function viewRecipe(recipe) {
                document.querySelector('.output-section h2').innerText = recipe.title;
                document.querySelector('.recipe-content').innerHTML = `
                    <h4 style='font-weight: bold;'>Ingredients:</h4>
                    \${recipe.ingredients.map(ingredient => `<p>\${ingredient}</p>`).join('')}
                    <h4 style='font-weight: bold;'>Instructions:</h4>
                    \${recipe.instructions.map(instruction => `<p>\${instruction}</p>`).join('')}
                `;
                if (recipe.imageUrl) {
                    document.querySelector('.recipe-image').src = recipe.imageUrl;
                }
                document.querySelector('.output-section').style.display = 'block'; // Show output section
            };
                // Define the storeRecipe function here
            function storeRecipe(recipe) {
                let recipes = JSON.parse(localStorage.getItem('recentRecipes')) || [];
                recipes.unshift(recipe);
                if (recipes.length > 20) {
                    recipes = recipes.slice(0, 20);
                }
                localStorage.setItem('recentRecipes', JSON.stringify(recipes));
                document.addEventListener('DOMContentLoaded', function() {
                    updateRecipeSidebar();
                });
            };
        document.addEventListener('DOMContentLoaded', function() {
            // Array of loading messages
            const loadingMessages = [
                \"Texting Martha Stewart\",
                \"Checking the pantry\",
                \"Calibrating tastebuds\",
                \"Sniffing for freshness\"
            ];


            // Add this script to handle the loading message and clear existing recipe
            document.querySelector('form').addEventListener('submit', function() {
                document.getElementById('loadingMessage').style.display = 'block'; // Show loading message
                document.getElementById('loadingBarContainer').style.display = 'block'; // Show loading bar
                document.querySelector('.output-section').style.display = 'none'; // Hide existing recipe

                // Start loading bar animation
                let width = 0;
                const loadingBar = document.getElementById('loadingBar');
                const loadingPercentage = document.getElementById('loadingPercentage');
                const interval = setInterval(function() {
                    if (width >= 100) {
                        clearInterval(interval);
                    } else {
                        width++;
                        loadingBar.style.width = width + '%';
                        loadingPercentage.innerText = width + '%';

                        // Update loading message every second
                        if (width % 20 === 0) { // Change message every 20%
                            const messageIndex = (width / 20) % loadingMessages.length; // Cycle through messages
                            document.getElementById('loadingMessage').innerText = loadingMessages[messageIndex];
                        }
                    }
                }, 50); // Update every 50ms for a total of 5 seconds
            });



            // Call this function on page load to populate the sidebar
            updateRecipeSidebar();
            const clearAllButton = document.getElementById('clearAllRecipes');
            if (clearAllButton) {
                clearAllButton.addEventListener('click', clearAllRecipes);
            }
        });
        const recipe = {
            title: " . json_encode($recipeTitle) . ",
            ingredients: " . json_encode($ingredientsArray) . ",
            instructions: " . json_encode($instructionsArray) . ",
            imageUrl: " . json_encode($imageUrl) . "
        };
        storeRecipe(recipe);
    </script>";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Strictly. Recipes.</title>
    <link rel="stylesheet" href="styles.css"> <!-- Link to the CSS file -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:ital,wght@0,100..700;1,100..700&display=swap" rel="stylesheet">

</head>
<body>
<div class="sidebar">
    <div id="feedback">  
            <a href="mailto:strictlyrecipes@gmail.com">Feedback? Email me</a>
    </div>
            <h3>History</h3>
            <!-- <button id="clearAllRecipes">Clear All</button> -->
            <ul id="recipeSidebar"></ul>
        </div>
    <div class="main-content">
        <div class="container">
            <div class="input-section">
                <h1>Strictly Recipes</h1>
                <p id="tagline">Recipes for people tired of scrolling</p>
                <form method="POST" class="recipe-form">
                    <input type="text" id="ingredients" name="ingredients" placeholder="What's in your fridge?" required>
                    <select id="dietaryRestrictions" name="dietaryRestrictions">
                        <option value="none">No Restrictions</option>
                        <option value="gluten-free">Gluten-Free</option>
                        <option value="keto">Keto</option>
                        <option value="low-fodmap">Low-FODMAP</option>
                        <option value="vegan">Vegan</option>
                    </select>
                    <input type="submit" value="Cook!">
                </form>
            </div>

            <!-- Add this loading message element below the form -->
            <div id="loadingMessage" style="display: none;">Generating recipe...</div>
            <div id="loadingBarContainer" style="display: none; width: 100%;">
                <div id="loadingBar" style="width: 0%; height: 20px; background-color: #4caf50;"></div>
                <div id="loadingPercentage" style="text-align: center;">0%</div>
            </div>
            <div class="output-section" style="display: <?php echo $recipeGenerated ? 'block' : 'none'; ?>;"> <!-- Show if recipe is generated -->
                <h2 id="recipe-title"><?php echo $recipeTitle; ?></h2> <!-- Bold title -->
                <div class="recipe-container">
                    <?php if ($imageUrl): ?>
                        <img src="<?php echo htmlspecialchars($imageUrl); ?>" alt="Generated Recipe Image" class="recipe-image">
                    <?php endif; ?>
                    <div class="recipe-content">
                        <h4 style='font-weight: bold;'>Ingredients:</h4> <!-- Bold ingredients header -->
                        <?php foreach ($ingredientsArray as $ingredient): ?>
                            <?php if (!empty(trim($ingredient))): ?>
                                <p><?php echo htmlspecialchars(trim($ingredient)); ?></p> <!-- New line for each ingredient -->
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <h4 style='font-weight: bold;'>Instructions:</h4> <!-- Bold instructions header -->
                        <?php foreach ($instructionsArray as $instruction): ?>
                            <?php if (!empty(trim($instruction))): ?>
                                <p><?php echo htmlspecialchars(trim($instruction)); ?></p> <!-- New line for each instruction -->
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function updateRecipeSidebar() {
            const sidebar = document.getElementById('recipeSidebar');
            if (!sidebar) {
                console.error('Sidebar element not found');
                return; // Exit if the sidebar is not found
            }
            sidebar.innerHTML = ''; // Clear existing sidebar content
            const recipes = JSON.parse(localStorage.getItem('recentRecipes')) || [];
            recipes.forEach((recipe) => {
                const listItem = document.createElement('li');
                listItem.innerText = recipe.title;
                listItem.onclick = () => viewRecipe(recipe); // Set click handler
                sidebar.appendChild(listItem);
            });
        }

        function viewRecipe(recipe) {
            document.querySelector('.output-section h2').innerText = recipe.title;
            document.querySelector('.recipe-content').innerHTML = `
                <h4 style='font-weight: bold;'>Ingredients:</h4>
                ${recipe.ingredients.map(ingredient => `<p>${ingredient}</p>`).join('')}
                <h4 style='font-weight: bold;'>Instructions:</h4>
                ${recipe.instructions.map(instruction => `<p>${instruction}</p>`).join('')}
            `;
            if (recipe.imageUrl) {
                document.querySelector('.recipe-image').src = recipe.imageUrl;
            }
            document.querySelector('.output-section').style.display = 'block'; // Show output section
        }

        document.addEventListener('DOMContentLoaded', function() {
            updateRecipeSidebar(); // Call this function to populate the sidebar on page load
        });
    </script>
</body>
</html>