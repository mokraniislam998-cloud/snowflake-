import os
import mimetypes
import requests

API_URL = "http://localhost:8000/predict"
IMAGE_DIR = r"C:\Users\User\Desktop\Cancer\Brain Cancer\brain_glioma"
THRESHOLD = 0.70

total = 0
correct_high_conf = 0

for fname in os.listdir(IMAGE_DIR):
    if not fname.lower().endswith((".jpg", ".jpeg", ".png")):
        continue

    total += 1
    path = os.path.join(IMAGE_DIR, fname)

    mime, _ = mimetypes.guess_type(path)
    if mime is None:
        mime = "image/jpeg"

    with open(path, "rb") as f:
        r = requests.post(API_URL, files={"file": (fname, f, mime)})

    if r.status_code != 200:
        print(f"[ERROR] {fname}: {r.status_code} -> {r.text}")
        continue

    data = r.json()
    label = data["predicted_label"]
    conf = data["confidence"]

    if label == "brain_glioma" and conf >= THRESHOLD:
        correct_high_conf += 1

    print(f"{fname:35s} -> {label:12s} | {conf*100:5.1f}%")

print("\n================ RESULTATS ================")
print(f"Total images testées : {total}")
print(f"Prédites 'brain_glioma' avec confiance > 70% : {correct_high_conf}")
print(f"Pourcentage : {100*correct_high_conf/total:.2f}%")
