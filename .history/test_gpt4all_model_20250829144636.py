from gpt4all import GPT4All

model_path = r"C:\Users\itsju\AppData\Local\nomic.ai\GPT4All\mistral-7b-instruct-v0.1.Q4_0.gguf"

# Try to load the model and generate a simple output
try:
    model = GPT4All(model_path, allow_download=False)
    prompt = "Hello! Can you tell me about yourself?"
    output = model.generate(prompt, max_tokens=100)
    print("Model output:")
    print(output)
except Exception as e:
    print(f"Error: {e}")