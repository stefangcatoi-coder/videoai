import whisper
import sys
import json
import numpy as np
import subprocess
import os

def transcribe():
    # Read binary audio from stdin
    audio_data = sys.stdin.buffer.read()
    if not audio_data:
        return

    # Convert audio data to float32 numpy array using ffmpeg
    process = subprocess.Popen(
        ['ffmpeg', '-i', 'pipe:0', '-f', 'f32le', '-ac', '1', '-ar', '16000', 'pipe:1'],
        stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL
    )
    out, _ = process.communicate(input=audio_data)
    audio_array = np.frombuffer(out, np.float32)

    # Load model (use base for speed/memory)
    model = whisper.load_model("base")

    # Transcribe
    result = model.transcribe(audio_array, word_timestamps=True)

    # Print JSON result to stdout
    print(json.dumps(result))

if __name__ == "__main__":
    transcribe()
