from fastapi import FastAPI, File, UploadFile, HTTPException
import torch
import torch.nn as nn
import torch.nn.functional as F
from PIL import Image
import torchvision.transforms as T
import io

# -------- Model (IDENTIQUE à l'entraînement) --------
class SimpleCNN(nn.Module):
    def __init__(self, num_classes=3):
        super().__init__()
        self.features = nn.Sequential(
            nn.Conv2d(3, 32, 3, padding=1), nn.BatchNorm2d(32), nn.ReLU(), nn.MaxPool2d(2),
            nn.Conv2d(32, 64, 3, padding=1), nn.BatchNorm2d(64), nn.ReLU(), nn.MaxPool2d(2),
            nn.Conv2d(64, 128, 3, padding=1), nn.BatchNorm2d(128), nn.ReLU(), nn.MaxPool2d(2),
            nn.Conv2d(128, 256, 3, padding=1), nn.BatchNorm2d(256), nn.ReLU(), nn.MaxPool2d(2),
            nn.Conv2d(256, 512, 3, padding=1), nn.BatchNorm2d(512), nn.ReLU(), nn.MaxPool2d(2),

            # ✅ Rend la sortie toujours en 7x7 (évite mismatch si img_size != 224)
            nn.AdaptiveAvgPool2d((7, 7)),
        )
        self.classifier = nn.Sequential(
            nn.Flatten(),
            nn.Linear(512 * 7 * 7, 512),
            nn.ReLU(),
            nn.Dropout(0.5),
            nn.Linear(512, 256),
            nn.ReLU(),
            nn.Dropout(0.5),
            nn.Linear(256, num_classes),
        )

    def forward(self, x):
        x = self.features(x)
        return self.classifier(x)

# -------- Load checkpoint --------
device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
ckpt = torch.load("checkpoint_best.pth", map_location=device)

class_names = ckpt["class_names"]
img_size = int(ckpt.get("img_size", 224))  # taille utilisée pour resize

model = SimpleCNN(num_classes=len(class_names)).to(device)
model.load_state_dict(ckpt["model_state_dict"])
model.eval()

# -------- Preprocess --------
transform = T.Compose([
    T.Resize((img_size, img_size)),
    T.ToTensor(),
    # Si tu avais Normalize à l'entraînement, ajoute-le ici !
])

app = FastAPI(title="Cancer Classification API", version="1.0")

@app.get("/")
def root():
    return {"status": "ok", "device": str(device), "classes": class_names}

@app.post("/predict")
async def predict(file: UploadFile = File(...)):
    if file.content_type not in ["image/jpeg", "image/png", "image/jpg"]:
        raise HTTPException(status_code=400, detail="Upload a JPG or PNG image")

    img_bytes = await file.read()

    try:
        img = Image.open(io.BytesIO(img_bytes)).convert("RGB")
    except Exception:
        raise HTTPException(status_code=400, detail="Invalid image file")

    x = transform(img).unsqueeze(0).to(device)

    with torch.no_grad():
        logits = model(x)
        probs = F.softmax(logits, dim=1)[0]
        pred_idx = int(torch.argmax(probs).item())

    confidence = float(probs[pred_idx].item())

    return {
        "predicted_index": pred_idx,
        "predicted_label": class_names[pred_idx],
        "confidence": confidence,
        "confidence_pct": round(confidence * 100, 2),
        "probabilities": {
            class_names[i]: round(float(probs[i].item()) * 100, 2)
            for i in range(len(class_names))
        }
    }
