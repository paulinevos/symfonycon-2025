# SymfonyCon Amsterdam 2025 - Ancient Artefact Matcher

A Symfony application that uses AI to match photos and descriptions with ancient artefacts from the "Below the Surface" dataset.

## Prerequisites

- PHP 8.2 or higher
- Composer
- MongoDB PHP Extension
- MongoDB Atlas account (or local MongoDB instance)
- OpenAI API key
- Voyage AI API key

## Installation

### 1. Clone the Project

```bash
git clone <repository-url> amsterdam-artefact-matcher
cd amsterdam-artefact-matcher
```

### 2. Install MongoDB PHP Extension

Install the MongoDB extension using `pie`:

```bash
pie install mongodb/mongodb-extension
```

Verify the installation:

```bash
php --ri mongodb
```

You should see MongoDB extension information if it's properly installed.

### 3. Install Dependencies

```bash
composer install
```

### 4. Set Up MongoDB Atlas

Create a free MongoDB Atlas cluster:

1. Go to [MongoDB Atlas](https://www.mongodb.com/cloud/atlas/register)
2. Sign up for a free account
3. Create a new cluster (the free M0 tier is sufficient for this project)
4. Create a database user with read/write permissions
5. Whitelist your IP address (or use `0.0.0.0/0` for testing)
6. Get your connection string from the "Connect" button

**Note:** The free M0 tier on MongoDB Atlas provides:
- 512 MB storage
- Shared RAM
- No credit card required
- Perfect for development and small projects

### 5. Configure Environment Variables

Create a `.env.local` file in the project root:

```bash
cp .env .env.local
```

Edit `.env.local` and set your configuration:

```dotenv
# MongoDB Atlas connection string
MONGODB_URI=mongodb+srv://<username>:<password>@<cluster>.mongodb.net/?retryWrites=true&w=majority

# Database name
MONGODB_DB=symfonycon-amsterdam

# Voyage AI API key (for embeddings)
VOYAGE_API_KEY=your_voyage_api_key_here

# OpenAI API key (for AI features)
OPENAI_API_KEY=your_openai_api_key_here

# Application URL
DEFAULT_URI=http://localhost:8000
```

**Getting API Keys:**
- **Voyage AI:** Sign up at [Voyage AI](https://www.voyageai.com/) to get your API key
- **OpenAI:** Get your API key from [OpenAI Platform](https://platform.openai.com/api-keys)

### 6. Create MongoDB Schema

Create the required indexes:

```bash
bin/console doctrine:mongodb:schema:create
```

### 7. Load Artefacts Dataset

Import the artefacts from the "Below the Surface" dataset:

```bash
bin/console app:load-artefacts
```

This command will:
- Download the dataset from the Below the Surface API
- Parse the CSV data
- Create artefact documents with summaries and images
- Persist them to your MongoDB database

This process may take several minutes depending on your connection speed.

### 8. Vectorize the Artefacts

Generate embeddings for all artefacts using AI:

```bash
bin/console ai:store:vectorize
```

This command will:
- Process artefacts in chunks of 500
- Generate vector embeddings using the Voyage AI API
- Store the embeddings in MongoDB for similarity search

**Note:** This process can take a while depending on the number of artefacts and API rate limits.

## Running the Application

Start the Symfony development server:

```bash
symfony serve
```

Visit `http://localhost:8000` in your browser to start matching artefacts!

## Features

- **Text Search:** Describe an artefact you're looking for
- **Image Search:** Upload a photo or use your camera to find similar artefacts
- **Drag & Drop:** Drag images directly onto the upload area
- **Search History:** Recent searches are saved in your browser (including images)
- **AI-Powered Matching:** Uses embeddings and vector similarity search

## Available Commands

- `bin/console app:load-artefacts` - Load artefacts from the dataset
- `bin/console ai:store:vectorize` - Generate embeddings for artefacts
- `bin/console doctrine:mongodb:schema:create` - Create MongoDB schema

## Troubleshooting

### MongoDB Extension Not Found

If you get "Extension mongodb not found":
- Make sure you've installed it with `pie install mongodb/mongodb-extension`
- Check your `php.ini` file includes `extension=mongodb.so`
- Restart your web server/PHP-FPM

### Connection to MongoDB Atlas Failed

- Verify your connection string in `.env.local`
- Make sure your IP is whitelisted in MongoDB Atlas
- Check your username and password are correct
- Ensure the database user has proper permissions

### API Key Errors

- Verify your API keys are correctly set in `.env.local`
- Check that your OpenAI/Voyage AI accounts have sufficient credits
- Ensure there are no extra spaces or quotes around the keys


## Credits

Dataset provided by [Below the Surface Amsterdam](https://belowthesurface.amsterdam/).
Project created by [Pauline Vos](https://github.com/paulinevos) for SymfonyCon 2025 Amsterdam.
