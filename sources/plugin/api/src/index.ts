// SPDX-License-Identifier: GPL-2.0-or-later
//
// The entry point that unraid-api's plugin loader imports: an `adapter` and an `ApiModule`.

import { Module } from '@nestjs/common';

import { TofumanMutationResolver, TofumanQueryResolver, TofumanRootResolver } from './resolvers.js';
import { DEFAULT_SHIM, TofumanService } from './service.js';

export const adapter = 'nestjs';

@Module({
  providers: [
    { provide: TofumanService, useValue: new TofumanService(process.env['TOFUMAN_SHIM'] ?? DEFAULT_SHIM) },
    TofumanRootResolver,
    TofumanQueryResolver,
    TofumanMutationResolver,
  ],
})
class TofumanApiModule {}

export const ApiModule = TofumanApiModule;
