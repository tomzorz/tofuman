// SPDX-License-Identifier: GPL-2.0-or-later
//
// The entry point that unraid-api's plugin loader imports: an `adapter` and an `ApiModule`.

import { Logger, Module, type OnModuleInit } from '@nestjs/common';

import { LOADED_FILE, moduleVersion, recordLoad } from './loaded.js';
import { TofumanMutationResolver, TofumanQueryResolver, TofumanRootResolver } from './resolvers.js';
import { DEFAULT_SHIM, TofumanService } from './service.js';

export const adapter = 'nestjs';

@Module({
  providers: [
    { provide: TofumanService, useValue: new TofumanService(DEFAULT_SHIM) },
    TofumanRootResolver,
    TofumanQueryResolver,
    TofumanMutationResolver,
  ],
})
class TofumanApiModule implements OnModuleInit {
  onModuleInit(): void {
    const problem = recordLoad(process.env['TOFUMAN_LOADED_FILE'] ?? LOADED_FILE, moduleVersion(), process.pid, new Date());
    if (problem !== null) {
      new Logger('tofuman').warn(problem);
    }
  }
}

export const ApiModule = TofumanApiModule;
