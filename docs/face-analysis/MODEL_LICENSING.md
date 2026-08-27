# Face-analysis model and runtime record

This record applies to FPA-P09-S04's private, non-commercial development and
evaluation use. Model weights are deliberately stored under the gitignored
`.local/models/` directory and are not distributed with fambam.

## Pinned runtime

| Component | Version | Package checksum | Licence confirmation |
| --- | --- | --- | --- |
| InsightFace Python library | `1.0.1` | PyPI wheel SHA-256 `5f373f6fedbdda5cbc59a34ca386a75a2995cdaf6899402590ae9eb4308fc2e8` | The installed package's `METADATA` contains its own README licence section and states that the Python library code is MIT-licensed. |
| ONNX Runtime | `1.29.0` | Platform wheels are pinned by SHA-256 in `apps/image-ai/uv.lock`. | MIT, confirmed from the installed distribution and the upstream ONNX Runtime repository. |

The InsightFace library's code licence does not grant a commercial licence for
the pretrained model weights.

## Pinned model pack

- Model: InsightFace `buffalo_l` v0.7.
- Official source:
  `https://github.com/deepinsight/insightface/releases/download/v0.7/buffalo_l.zip`
- Archive SHA-256:
  `80ffe37d8a5940d59a7384c201a2a38d4741f2f3c51eef46ebb28218a7b0ca2f`.
- Detector: `det_10g.onnx`, SHA-256
  `5838f7fe053675b1c7a08b633df49e7af5495cee0493c7dcf6697200b85b5b91`.
- Embedding model: `w600k_r50.onnx`, SHA-256
  `4c06341c33c2ca1f86781dab0e829f88ad5b64be9fba56e56bc9ebdefc619e43`.
- Additional pack files are also verified before InsightFace scans the model
  directory: `1k3d68.onnx`
  (`df5c06b8a0c12e422b2ed8947b8869faa4105387f199c477af038aa01f9a45cc`),
  `2d106det.onnx`
  (`f001b856447c413801ef5c42091ed0cd516fcd21f2d6b79635b1e733a7109dbf`)
  and `genderage.onnx`
  (`4fde69b1c810857b88c64a335084f1c3fe8f01246c9a191b48c7bb756d6652fb`).

The downloaded archive was inspected directly. It contains five ONNX files
and no embedded licence document. This absence is recorded rather than
silently treating the library's MIT licence as applying to the weights. The
installed InsightFace `1.0.1` package metadata states that pretrained models
provided with the library, whether downloaded automatically or manually, are
available for non-commercial research only. The official InsightFace Model Zoo
states the same restriction for all published model packs and identifies
`buffalo_l` as one of those packs.

Accordingly, fambam treats these exact weights as **non-commercial research
only**. This is sufficient only for the present private, non-commercial
development and evaluation. Before any commercial deployment, distribution or
use, the licence must be reviewed again and an appropriate commercial model
licence obtained from InsightFace where required.

## Integrity and loading rule

The application identifies this exact pack using the archive checksum above.
At provider construction it independently checks every file in the official
pack before InsightFace scans or ONNX Runtime loads the directory. Missing,
substituted or modified files fail closed. Execution providers do not enter
`config_hash`; logical detection and embedding configuration does.
