import requests

API_URL = "http://localhost:4891/v1/chat/completions"
MODEL_NAME = "mistral-7b-instruct-v0.1.Q4_0.gguf"  # Use the model name as shown in the API server
COLLECTIONS = ["YourCollectionName"]  # Replace with your actual collection name(s)

def chat_with_localdocs(prompt):
    payload = {
        "model": MODEL_NAME,
        "messages": [
            {"role": "user", "content": prompt}
        ],
        "local_docs": {
            "collections": COLLECTIONS
        }
    }
    response = requests.post(API_URL, json=payload)
    response.raise_for_status()
    data = response.json()
    print("AI:", data["choices"][0]["message"]["content"])

if __name__ == "__main__":
    chat_with_localdocs("What information do you have about X?")