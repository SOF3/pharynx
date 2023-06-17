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
const fsPromises = __importStar(require("fs/promises"));
const path = __importStar(require("path"));
const yaml = __importStar(require("js-yaml"));
const core = __importStar(require("@actions/core"));
const exec = __importStar(require("@actions/exec"));
const github = __importStar(require("@actions/github"));
const http = __importStar(require("@actions/http-client"));
const io = __importStar(require("@actions/io"));
const tc = __importStar(require("@actions/tool-cache"));
const composer = core.getBooleanInput("composer");
let pharynxVersion = core.getInput("pharynx-version");
const pluginDir = core.getInput("plugin-dir");
const additionalSources = core.getMultilineInput("additionalSources");
const stagePoggit = core.getBooleanInput("stage-poggit");
const httpClient = new http.HttpClient("pharynx-action");
(() => __awaiter(void 0, void 0, void 0, function* () {
    if (pharynxVersion === "latest") {
        core.info("Detecting latest pharynx version");
        const resp = yield httpClient.getJson("https://github.com/SOF3/pharynx/releases/latest", {
            [http.Headers.Accept]: "application/json",
        });
        if (resp.result === null) {
            return core.setFailed("Failed querying GitHub API for pharynx latest tag");
        }
        pharynxVersion = resp.result.tag_name;
    }
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
    const pharynxExitCode = yield exec.exec("php", args);
    if (pharynxExitCode !== 0) {
        return core.setFailed(`pharynx exited with ${pharynxExitCode}`);
    }
    core.setOutput("output-dir", outputDir);
    core.setOutput("output-phar", outputPhar);
    if (stagePoggit && github.context.eventName) {
        const payload = github.context.payload;
        const headCommit = payload.head_commit;
        if (headCommit !== null) {
            const srcBranch = github.context.ref.split("/").slice(2).join("/");
            const stageBranch = `poggit/${srcBranch}`;
            const checkoutExitCode = yield exec.exec("git", ["checkout", stageBranch]);
            if (checkoutExitCode !== 0) {
                const orphanExitCode = yield exec.exec("git", ["checkout", "-b", stageBranch]);
                if (orphanExitCode !== 0) {
                    return core.setFailed(`git checkout --orphan exited with ${orphanExitCode}`);
                }
                const pluginYmlBuf = yield fsPromises.readFile(path.join(outputDir, "plugin.yml"), "utf8");
                const pluginYml = yield yaml.load(pluginYmlBuf);
                if (typeof pluginYml !== "object" || pluginYml === null || typeof pluginYml.name !== "string") {
                    return core.setFailed("cannot detect plugin name from plugin.yml");
                }
                const pluginName = pluginYml.name;
                yield fsPromises.writeFile(".poggit.yml", JSON.stringify({
                    projects: {
                        [pluginName]: {
                            path: ".",
                        },
                    },
                }));
            }
            if ((yield exec.exec("git", ["rm", "-r", "--cached", "-f", "."])) !== 0) {
                return core.setFailed("cannot clean git directory");
            }
            const addArgs = ["add", ".poggit.yml"];
            if (yield fsExists("LICENSE")) {
                addArgs.push("LICENSE");
            }
            if ((yield exec.exec("git", addArgs)) !== 0) {
                return core.setFailed("cannot add files");
            }
            if ((yield exec.exec("git", ["clean", "-dfxf"])) !== 0) {
                return core.setFailed("cannot clean files");
            }
            yield io.cp(outputDir, ".", { recursive: true, copySourceDirectory: false });
            if ((yield exec.exec("git", [
                "-c", "user.name=github-actions[bot]",
                "-c", "user.email=41898282+github-actions[bot]@users.noreply.github.com",
                "commit",
                "-m",
                `stage(${headCommit.id}): ${headCommit.message}`,
            ])) !== 0) {
                return core.setFailed("cannot create commit");
            }
            if ((yield exec.exec("git", ["push", "origin", stageBranch])) !== 0) {
                return core.setFailed("cannot clean files");
            }
        } // else, nothing to build
    }
}))();
function fsExists(file) {
    return __awaiter(this, void 0, void 0, function* () {
        try {
            fsPromises.stat(file);
            return true;
        }
        catch (err) {
            if (err.code === "ENOENT") {
                return false;
            }
            throw err;
        }
    });
}
