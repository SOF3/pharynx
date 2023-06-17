"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
Object.defineProperty(exports, "__esModule", { value: true });
const crypto = __importStar(require("crypto"));
const path = __importStar(require("path"));
const core = __importStar(require("@actions/core"));
const exec = __importStar(require("@actions/exec"));
const tc = __importStar(require("@actions/tool-cache"));
const composer = core.getInput("composer") === "true";
const composerVersion = core.getInput("composer-version");
const pharynxVersion = core.getInput("pharynx-version");
const pluginDir = core.getInput("plugin-dir");
const additionalSources = core.getInput("additionalSources").split(":").filter(s => s.length > 0);
(() => __awaiter(void 0, void 0, void 0, function* () {
    let composerPharPath;
    core.info(`Downloading pharynx from https://github.com/SOF3/pharynx/releases/download/${pharynxVersion}/pharynx.phar`);
    const pharynxDownload = yield tc.downloadTool(`https://github.com/SOF3/pharynx/releases/download/${pharynxVersion}/pharynx.phar`);
    const pharynxCachePath = yield tc.cacheFile(pharynxDownload, "pharynx.phar", "pharynx", pharynxVersion);
    const pharynxPharPath = path.join(pharynxCachePath, "pharynx.phar");
    const outputId = crypto.randomBytes(8).toString("hex");
    const outputDir = path.join("/tmp", outputId);
    const outputPhar = path.join("/tmp", `${outputId}.phar`);
    let args = [
        "-dphar.readonly=0",
        pharynxPharPath,
        "-i", pluginDir,
        "-o", outputDir,
        `-p=${outputPhar}`,
    ];
    if (composer) {
        args.push("-c");
    }
    for (const additionalSource of additionalSources) {
        args.push("-s", additionalSource);
    }
    yield exec.exec("php", args);
    core.setOutput("output-dir", outputDir);
    core.setOutput("output-phar", outputPhar);
}))();
